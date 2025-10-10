<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>DropIT · PLT</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
</head>
<body>
  <main class="container-fluid py-3">
    <div class="row g-3">
      <aside class="col-12 col-md-3 col-lg-2">
        <div id="sidebar" class="card shadow-sm p-2"></div>
      </aside>
      <section class="col-12 col-md-9 col-lg-10">
      <div class="col-12">
        <div class="card shadow-sm">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <h5 class="card-title mb-0"><i class="fa-solid fa-truck-fast me-2 text-primary"></i>PLT – Shipments</h5>
              <div class="d-flex gap-2">
                <select id="status" class="form-select form-select-sm" style="width:180px">
                  <option value="">All Status</option>
                  <option>planned</option>
                  <option>in_transit</option>
                  <option>delayed</option>
                  <option>arrived</option>
                  <option>cancelled</option>
                </select>
                <input id="project_id" class="form-control form-control-sm" style="width:160px" placeholder="Project ID"/>
                <button id="filterBtn" class="btn btn-outline-primary btn-sm">Apply</button>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead class="table-light">
                  <tr>
                    <th>ID</th><th>Project</th><th>Carrier</th><th>Tracking #</th><th>Status</th><th>Created</th><th></th>
                  </tr>
                </thead>
                <tbody id="rows"><tr><td colspan="7" class="text-muted">Loading…</td></tr></tbody>
              </table>
            </div>
            <div class="d-flex align-items-center gap-2">
              <button id="prev" class="btn btn-outline-secondary btn-sm">Prev</button>
              <button id="next" class="btn btn-outline-secondary btn-sm">Next</button>
              <span id="meta" class="text-muted small"></span>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div id="detailCard" class="card shadow-sm d-none">
          <div class="card-body">
            <h5 class="card-title mb-3"><i class="fa-solid fa-box-open me-2 text-primary"></i>Shipment Detail <span id="shid"></span></h5>
            <div id="detail" class="row g-3"></div>
            <hr/>
            <div class="d-flex align-items-center justify-content-between mb-2">
              <h6 class="mb-0"><i class="fa-solid fa-location-dot me-2 text-secondary"></i>Tracking Events</h6>
              <div class="d-flex gap-2">
                <input id="ev_type" class="form-control form-control-sm" placeholder="Event type"/>
                <input id="ev_loc" class="form-control form-control-sm" placeholder="Location"/>
                <input id="ev_time" class="form-control form-control-sm" placeholder="YYYY-MM-DD HH:MM:SS"/>
                <button id="ev_add" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus"></i></button>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead class="table-light"><tr><th>ID</th><th>Type</th><th>Location</th><th>Time</th><th></th></tr></thead>
                <tbody id="ev_rows"><tr><td colspan="5" class="text-muted">No data</td></tr></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      </section>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/common.js"></script>
  <script>
    let page=1, limit=10, status='', project_id='';
    async function load(){
      const res = await Api.get(`api/shipments.php${Api.q({status, project_id, page, limit})}`);
      const items = res.data.items||[]; const total = res.data.total||0;
      document.getElementById('rows').innerHTML = items.map(s=>`<tr>
        <td>${s.id}</td><td>${s.project_code||''}</td><td>${s.carrier||''}</td><td>${s.tracking_no||''}</td>
        <td><span class="badge text-bg-secondary">${s.status}</span></td>
        <td>${(s.created_at||'').toString().replace('T',' ').replace('Z','')}</td>
        <td><button class="btn btn-sm btn-outline-primary" data-id="${s.id}"><i class="fa-solid fa-up-right-from-square"></i></button></td>
      </tr>`).join('') || '<tr><td colspan="7" class="text-muted">No results</td></tr>';
      const pages = Math.max(1, Math.ceil((total||0)/limit));
      document.getElementById('prev').disabled = page<=1;
      document.getElementById('next').disabled = page>=pages;
      document.getElementById('meta').textContent = `Page ${page}/${pages} • ${total} shipments`;
    }
    document.getElementById('filterBtn').addEventListener('click', ()=>{ status=document.getElementById('status').value; project_id=document.getElementById('project_id').value; page=1; load(); });
    document.getElementById('prev').addEventListener('click', ()=>{ if(page>1){ page--; load(); } });
    document.getElementById('next').addEventListener('click', ()=>{ page++; load(); });
    document.getElementById('rows').addEventListener('click', (e)=>{ const b=e.target.closest('button[data-id]'); if(!b) return; openDetail(parseInt(b.getAttribute('data-id'))); });
    async function openDetail(id){
      document.getElementById('detailCard').classList.remove('d-none');
      document.getElementById('shid').textContent = `#${id}`;
      const d = await Api.get(`api/shipments.php?id=${id}`);
      const s = d.data;
      document.getElementById('detail').innerHTML = `
        <div class="col-12 col-md-4"><div class="text-muted small">Project</div><div>${s.project_code||''}</div></div>
        <div class="col-12 col-md-4"><div class="text-muted small">Carrier</div><div>${s.carrier||''}</div></div>
        <div class="col-12 col-md-4"><div class="text-muted small">Tracking #</div><div>${s.tracking_no||''}</div></div>
        <div class="col-12 col-md-4"><div class="text-muted small">Origin</div><div>${s.origin||''}</div></div>
        <div class="col-12 col-md-4"><div class="text-muted small">Destination</div><div>${s.destination||''}</div></div>
        <div class="col-12 col-md-4"><div class="text-muted small">ETA</div><div>${s.eta||''}</div></div>
        <div class="col-12 col-md-4"><div class="text-muted small">Status</div><div><span class="badge text-bg-secondary">${s.status||''}</span></div></div>`;
      await loadEvents(id);
      document.getElementById('ev_add').onclick = async ()=>{
        const ev = { shipment_id:id, event_type: document.getElementById('ev_type').value.trim(), location: document.getElementById('ev_loc').value.trim(), event_time: document.getElementById('ev_time').value.trim(), notes:'' };
        if(!ev.event_type || !ev.event_time){ alert('event_type and event_time are required'); return; }
        await Api.send('api/tracking_events.php','POST', ev);
        document.getElementById('ev_type').value=''; document.getElementById('ev_loc').value=''; document.getElementById('ev_time').value='';
        await loadEvents(id);
      };
    }
    async function loadEvents(id){
      const r = await Api.get(`api/tracking_events.php${Api.q({shipment_id:id, page:1, limit:50})}`);
      const rows = (r.data.items||[]).map(e=>`<tr>
        <td>${e.id}</td><td>${e.event_type}</td><td>${e.location||''}</td><td>${e.event_time}</td>
        <td><button class="btn btn-sm btn-outline-danger" data-del="${e.id}"><i class="fa-solid fa-trash"></i></button></td>
      </tr>`).join('') || '<tr><td colspan="5" class="text-muted">No events</td></tr>';
      document.getElementById('ev_rows').innerHTML = rows;
      document.getElementById('ev_rows').addEventListener('click', async (e)=>{
        const b = e.target.closest('button[data-del]'); if(!b) return; const eid=parseInt(b.getAttribute('data-del'));
        if(!confirm('Delete event #'+eid+'?')) return; await fetch(`api/tracking_events.php?id=${eid}`,{method:'DELETE'}); await loadEvents(id);
      });
    }
    load();
  </script>
</body>
</html>
