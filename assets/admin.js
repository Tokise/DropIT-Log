(function(){
  const app = document.getElementById('adminApp');

  async function apiGet(url){ const r = await fetch(url); if(!r.ok) throw new Error(await r.text()); return r.json(); }

  function statCard(title, items){
    const body = Object.entries(items).map(([k,v])=>`
      <div class="col-6 col-lg-4">
        <div class="border rounded p-3 h-100 d-flex flex-column">
          <div class="text-muted small">${k.replace(/_/g,' ')}</div>
          <div class="fs-4 fw-semibold">${v}</div>
        </div>
      </div>
    `).join('');
    return `
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">${title}</h5>
          <div class="row g-3">${body}</div>
        </div>
      </div>`;
  }

  function recentAudit(list){
    const rows = (list||[]).map(a=>`
      <tr>
        <td>${a.id}</td>
        <td><span class="badge bg-secondary">${a.entity_type}</span></td>
        <td>${a.entity_id ?? ''}</td>
        <td>${a.action}</td>
        <td>${a.email || ''}</td>
        <td>${(a.created_at||'').toString().replace('T',' ').replace('Z','')}</td>
      </tr>
    `).join('');
    return `
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Recent Audit</h5>
          <div class="table-responsive">
            <table class="table table-sm">
              <thead><tr><th>ID</th><th>Entity</th><th>Entity ID</th><th>Action</th><th>User</th><th>When</th></tr></thead>
              <tbody>${rows || '<tr><td colspan="6" class="text-muted">No audit logs</td></tr>'}</tbody>
            </table>
          </div>
        </div>
      </div>`;
  }

  async function render(){
    try{
      const res = await apiGet('api/dashboard.php');
      const counts = res.data ? res.data.counts : res.counts;
      const recent = res.data ? res.data.recent_audit : res.recent_audit;

      app.innerHTML = `
        <div class="col-12 col-xl-6">${statCard('PSM', counts.psm)}</div>
        <div class="col-12 col-xl-6">${statCard('PLT', counts.plt)}</div>
        <div class="col-12 col-xl-6">${statCard('SWS', counts.sws)}</div>
        <div class="col-12 col-xl-6">${statCard('ALMS', counts.alms)}</div>
        <div class="col-12 col-xl-6">${statCard('DTRS/DTLRS', counts.dtrs)}</div>
        <div class="col-12">${recentAudit(recent)}</div>
      `;
    }catch(e){
      app.innerHTML = `<div class="alert alert-danger">${e.message}</div>`;
    }
  }

  document.addEventListener('DOMContentLoaded', render);
})();
