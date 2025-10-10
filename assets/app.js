(function(){
  const el = (sel, ctx=document) => ctx.querySelector(sel);
  const els = (sel, ctx=document) => Array.from(ctx.querySelectorAll(sel));
  const app = el('#app');
  const yearEl = el('#year'); if (yearEl) yearEl.textContent = new Date().getFullYear();

  // Simple router
  const routes = {};
  function route(path, handler){ routes[path] = handler; }
  function navigate(hash){ window.location.hash = hash; }
  function parseHash(){
    const h = window.location.hash || '#/psm';
    const parts = h.slice(2).split('/'); // remove #/
    return { raw: h, segs: parts };
  }
  async function render(){
    const { segs } = parseHash();
    const key = segs[0] || 'psm';
    highlightNav('#/'+key);
    if (routes[key]) await routes[key](segs.slice(1)); else await routes['psm']([]);
  }
  function highlightNav(active){
    els('.nav a').forEach(a=>{
      if (a.getAttribute('href') === '#'+active) a.classList.add('active');
      else a.classList.remove('active');
    });
  }

  // Utilities
  function qparams(obj){
    const p = Object.entries(obj).filter(([,v])=>v !== undefined && v !== null && v !== '').map(([k,v])=>`${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&');
    return p ? ('?'+p) : '';
  }
  async function apiGet(url){
    const r = await fetch(url);
    if (!r.ok) throw new Error(await r.text());
    return r.json();
  }
  async function apiSend(url, method, body){
    const r = await fetch(url,{method, headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
    if (!r.ok) throw new Error(await r.text());
    return r.json();
  }
  function table(headers, rowsHtml){
    return `<div class="card"><table class="table"><thead><tr>${headers.map(h=>`<th>${h}</th>`).join('')}</tr></thead><tbody>${rowsHtml}</tbody></table></div>`;
  }
  function pager({page, limit, total}, onPage){
    const pages = Math.max(1, Math.ceil(total/limit));
    return `<div class="controls"><div class="pager">
      <button ${page<=1?'disabled':''} data-page="${page-1}">Prev</button>
      <span class="small">Page ${page} / ${pages} (${total} items)</span>
      <button ${page>=pages?'disabled':''} data-page="${page+1}">Next</button>
    </div></div>`;
  }

  // Views
  route('sws', async () => {
    app.innerHTML = `
      <div class="grid cols-2">
        <div class="card"><h1>SWS - Smart Warehousing</h1><p class="small">Placeholder. Add inventory, locations, counts.</p></div>
        <div class="card"><h2>Quick Links</h2><ul>
          <li><a href="#/psm">Suppliers</a></li>
          <li><a href="#/plt">Shipments</a></li>
        </ul></div>
      </div>`;
  });

  // PSM - Suppliers List
  route('psm', async () => {
    let page = parseInt(new URLSearchParams(location.hash.split('?')[1]||'').get('page')||'1');
    let limit = 10;
    let q = new URLSearchParams(location.hash.split('?')[1]||'').get('q')||'';

    async function load(){
      app.innerHTML = `<div class="card"><h1>PSM - Suppliers</h1>
        <div class="controls">
          <input type="text" id="q" placeholder="Search name or code" value="${q}">
          <button id="searchBtn">Search</button>
        </div>
        <div id="list">Loading...</div>
      </div>`;
      try{
        const res = await apiGet(`api/suppliers.php${qparams({q, page, limit})}`);
        const rows = (res.items||[]).map(s=>`<tr>
          <td>${s.id}</td>
          <td>${s.name}</td>
          <td>${s.code||''}</td>
          <td>${s.country||''}</td>
          <td><span class="badge">${s.is_active? 'active':'inactive'}</span></td>
        </tr>`).join('');
        const html = table(['ID','Name','Code','Country','Status'], rows) + pager(res, p=>p);
        el('#list').innerHTML = html;
        const pagerEl = el('#list .pager');
        if (pagerEl){
          pagerEl.addEventListener('click', (e)=>{
            const b = e.target.closest('button'); if (!b) return;
            const p = parseInt(b.getAttribute('data-page'));
            if (!isNaN(p)) { page = p; navigate(`#/psm?page=${page}&q=${encodeURIComponent(q)}`); }
          });
        }
        el('#searchBtn').onclick = ()=>{ q = el('#q').value.trim(); page=1; navigate(`#/psm?page=1&q=${encodeURIComponent(q)}`); };
        el('#q').addEventListener('keydown', (e)=>{ if (e.key==='Enter') el('#searchBtn').click(); });
      }catch(err){ el('#list').innerHTML = `<div class="small" style="color:#b91c1c">${err.message}</div>`; }
    }
    await load();
  });

  // PLT - Shipments list and detail
  route('plt', async (args) => {
    // Detail view #/plt/:id
    if (args[0]) return ShipmentDetailView(parseInt(args[0]));

    let page = parseInt(new URLSearchParams(location.hash.split('?')[1]||'').get('page')||'1');
    let limit = 10;
    let status = new URLSearchParams(location.hash.split('?')[1]||'').get('status')||'';
    let project_id = new URLSearchParams(location.hash.split('?')[1]||'').get('project_id')||'';

    async function load(){
      app.innerHTML = `<div class="card"><h1>PLT - Shipments</h1>
        <div class="controls">
          <select id="status">
            <option value="">All Status</option>
            ${['planned','in_transit','delayed','arrived','cancelled'].map(s=>`<option ${status===s?'selected':''} value="${s}">${s}</option>`).join('')}
          </select>
          <input type="number" id="project_id" placeholder="Project ID" value="${project_id}">
          <button id="filterBtn">Apply</button>
        </div>
        <div id="list">Loading...</div>
      </div>`;
      try{
        const res = await apiGet(`api/shipments.php${qparams({status, project_id, page, limit})}`);
        const rows = (res.items||[]).map(s=>`<tr>
          <td>${s.id}</td>
          <td>${s.project_code||''}</td>
          <td>${s.carrier||''}</td>
          <td>${s.tracking_no||''}</td>
          <td><span class="badge">${s.status}</span></td>
          <td>${(s.created_at||'').toString().replace('T',' ').replace('Z','')}</td>
          <td><button class="secondary" data-id="${s.id}">Open</button></td>
        </tr>`).join('');
        const html = table(['ID','Project','Carrier','Tracking #','Status','Created',''], rows) + pager(res, p=>p);
        el('#list').innerHTML = html;
        el('#filterBtn').onclick = ()=>{
          status = el('#status').value; project_id = el('#project_id').value; page=1;
          navigate(`#/plt?page=1&status=${encodeURIComponent(status)}&project_id=${encodeURIComponent(project_id)}`);
        };
        el('#list').addEventListener('click', (e)=>{
          const b = e.target.closest('button[data-id]'); if (!b) return;
          navigate(`#/${'plt'}/${b.getAttribute('data-id')}`);
        });
        const pagerEl = el('#list .pager');
        if (pagerEl){
          pagerEl.addEventListener('click', (e)=>{
            const b = e.target.closest('button'); if (!b) return;
            const p = parseInt(b.getAttribute('data-page'));
            if (!isNaN(p)) { page = p; navigate(`#/plt?page=${page}&status=${encodeURIComponent(status)}&project_id=${encodeURIComponent(project_id)}`); }
          });
        }
      }catch(err){ el('#list').innerHTML = `<div class="small" style="color:#b91c1c">${err.message}</div>`; }
    }
    await load();
  });

  async function ShipmentDetailView(id){
    app.innerHTML = `<div class="grid cols-2">
      <div class="card"><h1>Shipment #${id}</h1><div id="detail">Loading...</div></div>
      <div class="card"><h2>Tracking Events</h2>
        <div id="events">Loading...</div>
        <div class="controls" style="margin-top:12px">
          <input id="ev_type" placeholder="Event type"/>
          <input id="ev_loc" placeholder="Location"/>
          <input id="ev_time" placeholder="YYYY-MM-DD HH:MM:SS"/>
          <button id="ev_add">Add</button>
        </div>
      </div>
    </div>`;
    try{
      const detail = await apiGet(`api/shipments.php?id=${id}`);
      el('#detail').innerHTML = `<div class="grid cols-3">
        <div><div class="small">Project</div><div>${detail.project_code||''}</div></div>
        <div><div class="small">Carrier</div><div>${detail.carrier||''}</div></div>
        <div><div class="small">Tracking #</div><div>${detail.tracking_no||''}</div></div>
        <div><div class="small">Origin</div><div>${detail.origin||''}</div></div>
        <div><div class="small">Destination</div><div>${detail.destination||''}</div></div>
        <div><div class="small">ETA</div><div>${detail.eta||''}</div></div>
        <div><div class="small">Status</div><div><span class="badge">${detail.status||''}</span></div></div>
      </div>`;
      renderEvents(detail);
      el('#ev_add').onclick = async ()=>{
        const ev = {
          shipment_id: id,
          event_type: el('#ev_type').value.trim(),
          location: el('#ev_loc').value.trim(),
          event_time: el('#ev_time').value.trim(),
          notes: ''
        };
        if (!ev.event_type || !ev.event_time){ alert('event_type and event_time are required'); return; }
        try{
          await apiSend('api/tracking_events.php','POST', ev);
          el('#ev_type').value=''; el('#ev_loc').value=''; el('#ev_time').value='';
          await renderEvents({id});
        }catch(err){ alert(err.message); }
      };
    }catch(err){ el('#detail').innerHTML = `<div class="small" style="color:#b91c1c">${err.message}</div>`; }

    async function renderEvents(){
      try{
        const res = await apiGet(`api/tracking_events.php${qparams({shipment_id:id, page:1, limit:50})}`);
        const rows = (res.items||[]).map(e=>`<tr>
          <td>${e.id}</td>
          <td>${e.event_type}</td>
          <td>${e.location||''}</td>
          <td>${e.event_time}</td>
          <td><button class="secondary" data-del="${e.id}">Delete</button></td>
        </tr>`).join('');
        el('#events').innerHTML = table(['ID','Type','Location','Time',''], rows);
        el('#events').addEventListener('click', async (ev)=>{
          const idBtn = ev.target.closest('button[data-del]'); if (!idBtn) return;
          const eid = parseInt(idBtn.getAttribute('data-del'));
          if (!confirm('Delete event #'+eid+'?')) return;
          try{ await fetch(`api/tracking_events.php?id=${eid}`, {method:'DELETE'}); await renderEvents(); } catch(err){ alert(err.message); }
        });
      }catch(err){ el('#events').innerHTML = `<div class="small" style="color:#b91c1c">${err.message}</div>`; }
    }
  }

  // ALMS placeholder
  route('alms', async () => {
    app.innerHTML = `<div class="card"><h1>ALMS - Assets</h1><p class="small">Placeholder. Manage assets, work orders, maintenance plans.</p></div>`;
  });

  // DTRS/DTLRS placeholder
  route('dtrs', async () => {
    app.innerHTML = `<div class="card"><h1>DTRS/DTLRS - Documents</h1><p class="small">Placeholder. Upload, link and browse documents.</p></div>`;
  });

  window.addEventListener('hashchange', render);
  window.addEventListener('load', render);
})();
