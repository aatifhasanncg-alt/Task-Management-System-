// filters.js — Dynamic filter helpers for MISPro

// ── Dependent dropdown: Branch → Dept → Staff ─────────────────
document.addEventListener('DOMContentLoaded', function () {

  // When branch changes, reload dept staff
  const branchSel = document.getElementById('filter_branch');
  const deptSel   = document.getElementById('filter_dept');
  const staffSel  = document.getElementById('filter_staff');

  if (branchSel && staffSel) {
    branchSel.addEventListener('change', reloadStaff);
  }
  if (deptSel && staffSel) {
    deptSel.addEventListener('change', reloadStaff);
  }

  function reloadStaff() {
    const bId = branchSel?.value || 0;
    const dId = deptSel?.value   || 0;
    if (!staffSel) return;
    fetch(`${APP.url}/ajax/get_staff_by_admin.php?branch_id=${bId}&dept_id=${dId}`)
      .then(r => r.json())
      .then(data => {
        staffSel.innerHTML = '<option value="">All Staff</option>';
        data.forEach(s => {
          staffSel.innerHTML += `<option value="${s.id}">${s.full_name} — ${s.branch_name || ''}</option>`;
        });
      });
  }

  // Company search autocomplete
  const compSearch = document.getElementById('company_search_input');
  const compResults = document.getElementById('company_results');
  if (compSearch && compResults) {
    let timer;
    compSearch.addEventListener('input', function () {
      clearTimeout(timer);
      const q = this.value.trim();
      if (q.length < 2) { compResults.innerHTML = ''; compResults.style.display = 'none'; return; }
      timer = setTimeout(() => {
        fetch(`${APP.url}/ajax/get_companies.php?search=${encodeURIComponent(q)}`)
          .then(r => r.json())
          .then(data => {
            if (!data.length) { compResults.style.display = 'none'; return; }
            compResults.innerHTML = data.map(c => `
              <div class="autocomplete-item" onclick="selectCompany(${c.id},'${escHtml(c.company_name)}')">
                <strong>${escHtml(c.company_name)}</strong>
                <small class="text-muted ms-2">${c.pan_number || ''}</small>
              </div>`).join('');
            compResults.style.display = 'block';
          });
      }, 300);
    });
  }

  function selectCompany(id, name) {
    const inp = document.getElementById('company_id_hidden');
    const vis = document.getElementById('company_search_input');
    const res = document.getElementById('company_results');
    if (inp) inp.value = id;
    if (vis) vis.value = name;
    if (res) { res.innerHTML = ''; res.style.display = 'none'; }
  }

  // Date range quick buttons
  document.querySelectorAll('[data-date-preset]').forEach(btn => {
    btn.addEventListener('click', function () {
      const preset = this.dataset.datePreset;
      const fromEl = document.getElementById('filter_from');
      const toEl   = document.getElementById('filter_to');
      if (!fromEl || !toEl) return;
      const now = new Date();
      let from, to = fmtDate(now);
      switch(preset) {
        case 'today':
          from = to;
          break;
        case 'week':
          const d = new Date(now); d.setDate(d.getDate()-7);
          from = fmtDate(d);
          break;
        case 'month':
          from = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-01`;
          break;
        case 'quarter':
          const q = Math.floor(now.getMonth()/3);
          from = `${now.getFullYear()}-${String(q*3+1).padStart(2,'0')}-01`;
          break;
        case 'year':
          from = `${now.getFullYear()}-01-01`;
          break;
      }
      fromEl.value = from;
      toEl.value   = to;
      document.querySelectorAll('[data-date-preset]').forEach(b => b.classList.remove('active'));
      this.classList.add('active');
    });
  });

  function fmtDate(d) {
    return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
  }

  // Table column search (per-column filter rows)
  document.querySelectorAll('.col-search').forEach(inp => {
    inp.addEventListener('keyup', function () {
      const col = parseInt(this.dataset.col);
      const table = $(this).closest('table').DataTable();
      if (table) table.column(col).search(this.value).draw();
    });
  });
});

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}