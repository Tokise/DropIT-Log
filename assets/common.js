(function(){
  // Simple session check
  async function fetchUser(){
    try{
      const r = await fetch('api/me.php');
      if (!r.ok) throw new Error('unauth');
      const data = await r.json();
      return data.data || data;
    }catch(e){ return null; }
  }

  // Guard pages
  (async () => {
    const user = await fetchUser();
    if (!user) {
      if (!/index\.php$/i.test(location.pathname)) location.replace('index.php');
      return;
    }

    const role = user.role;
    const moduleKey = user.module || 'psm';

  // Allowed modules per role
  const modules = [
    { key:'sws', title:'SWS', href:'sws.php', icon:'fa-warehouse' },
    { key:'psm', title:'PSM', href:'psm.php', icon:'fa-handshake' },
    { key:'plt', title:'PLT', href:'plt.php', icon:'fa-truck-fast' },
    { key:'alms', title:'ALMS', href:'alms.php', icon:'fa-screwdriver-wrench' },
    { key:'dtrs', title:'DTRS/DTLRS', href:'dtrs.php', icon:'fa-file-lines' }
  ];
  function allowedKeys(){
    if (role === 'admin') return modules.map(m=>m.key);
    return [moduleKey];
  }

  // Build Topbar (logo left, notifications right)
  function ensureTopbar(){
    let top = document.getElementById('topbar');
    if (!top){
      top = document.createElement('nav');
      top.id = 'topbar';
      top.className = 'navbar navbar-expand navbar-dark bg-dark px-3';
      document.body.insertBefore(top, document.body.firstChild);
    }
    const homeHref = (role === 'admin') ? 'admin.php' : `${moduleKey}.php`;
    top.innerHTML = `
      <div class="container-fluid d-flex align-items-center justify-content-between">
        <a class="navbar-brand d-flex align-items-center" href="${homeHref}">
          <img src="assets/img/logo.png" alt="DropIT" style="height:24px" class="me-2"/>
          <span>DropIT</span>
        </a>
        <div class="d-flex align-items-center gap-3">
          <div class="dropdown">
            <button class="btn btn-sm btn-outline-light position-relative" id="notifBell" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
              <i class="fa-regular fa-bell"></i>
              <span id="notifBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none">0</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end p-0" aria-labelledby="notifBell" style="min-width:320px">
              <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
                <strong>Notifications</strong>
                <button id="markAllRead" class="btn btn-link btn-sm">Mark all read</button>
              </div>
              <div id="notifList" style="max-height:360px; overflow:auto">
                <div class="px-3 py-2 text-muted small">Loading…</div>
              </div>
            </div>
          </div>
          <div class="text-light small d-none d-md-block">${user.email} · ${role.toUpperCase()}</div>
         
        </div>
      </div>`;

    const lb = document.getElementById('logoutBtn');
    if (lb){ lb.addEventListener('click', ()=>{ fetch('api/logout.php').finally(()=>location.replace('index.php')); }); }
  }
  ensureTopbar();

  // Notifications logic
  async function loadNotifications(){
    try{
      const r = await fetch('api/notifications.php');
      if (!r.ok) throw new Error(await r.text());
      const data = await r.json();
      const items = data.data.items || [];
      const unread = items.filter(n => !Number(n.is_read)).length;
      
      console.log('Loaded notifications:', items.length, 'total,', unread, 'unread');
      
      const badge = document.getElementById('notifBadge');
      if (badge){
        if (unread > 0){ badge.textContent = unread; badge.classList.remove('d-none'); }
        else { badge.classList.add('d-none'); }
      }
      const list = document.getElementById('notifList');
      if (list){
        if (items.length === 0){ list.innerHTML = '<div class="px-3 py-2 text-muted small">No notifications</div>'; return; }
        list.innerHTML = items.map(n => `
          <a href="#" class="dropdown-item d-flex justify-content-between align-items-start ${Number(n.is_read)?'text-muted':''}" data-id="${n.id}">
            <div class="me-2">
              <div class="fw-semibold">${escapeHtml(n.title||'')}</div>
              <div class="small">${escapeHtml(n.message||'')}</div>
            </div>
            ${Number(n.is_read)?'':'<span class="badge bg-primary">New</span>'}
          </a>
        `).join('');
        // Click to mark read
        list.querySelectorAll('a.dropdown-item').forEach(a => {
          a.addEventListener('click', async (e)=>{
            e.preventDefault();
            const id = a.getAttribute('data-id');
            await fetch(`api/notifications.php?id=${id}`, {method:'PUT'});
            loadNotifications();
          });
        });
      }
    }catch(e){ 
      console.error('Failed to load notifications:', e);
    }
  }

  function escapeHtml(s){ return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

  const markAll = () => fetch('api/notifications.php', {method:'PUT', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ids: []})});
  const markAllBtnObserver = new MutationObserver(()=>{
    const btn = document.getElementById('markAllRead');
    if (btn){ btn.onclick = async ()=>{ const res = await fetch('api/notifications.php'); const data = await res.json(); const ids = (data.data.items||[]).filter(n=>!Number(n.is_read)).map(n=>n.id); if(ids.length){ await fetch('api/notifications.php', {method:'PUT', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ids})}); } loadNotifications(); } }
  });
  markAllBtnObserver.observe(document.body,{childList:true, subtree:true});

  loadNotifications();
  setInterval(loadNotifications, 30000);

  // Build Navbar
  const nav = document.getElementById('topnav');
  if (nav){
    const navModules = (role === 'admin') ? [{ key:'admin', title:'Dashboard', href:'admin.php', icon:'fa-gauge' }].concat(modules) : modules;
    const items = navModules.filter(m=> (role==='admin' ? ['admin'].concat(modules.map(mm=>mm.key)) : [moduleKey]).includes(m.key))
      .map(m=>`<li class="nav-item"><a class="nav-link ${location.pathname.endsWith(m.href)?'active':''}" href="${m.href}"><i class="fa-solid ${m.icon} me-1"></i>${m.title}</a></li>`).join('');
    nav.innerHTML = `
      <a class="navbar-brand" href="${(role==='admin' ? 'admin' : moduleKey)}.php"><i class="fa-solid fa-cubes-stacked me-2"></i>DropIT</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navitems"><span class="navbar-toggler-icon"></span></button>
      <div class="collapse navbar-collapse" id="navitems">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">${items}</ul>
        <div class="d-flex align-items-center gap-2">
          <button id="logoutBtn" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-right-from-bracket"></i></button>
        </div>
      </div>`;
    const lb = document.getElementById('logoutBtn');
    if (lb){ lb.addEventListener('click', ()=>{ fetch('api/logout.php').finally(()=>location.replace('index.php')); }); }
  }

  // Build Sidebar
  const sidebar = document.getElementById('sidebar');
  if (sidebar){
    const sideModules = (role === 'admin') ? [{ key:'admin', title:'Dashboard', href:'admin.php', icon:'fa-gauge' }].concat(modules) : modules;
    const items = sideModules.filter(m=> (role==='admin' ? ['admin'].concat(modules.map(mm=>mm.key)) : [moduleKey]).includes(m.key))
      .map(m=>`<a class="list-group-item list-group-item-action ${location.pathname.endsWith(m.href)?'active':''}" href="${m.href}"><i class="fa-solid ${m.icon} me-2"></i>${m.title}</a>`).join('');
    sidebar.innerHTML = `
      <div class="list-group list-group-flush">${items}</div>
      <button id="sbLogout" class="btn btn-outline-secondary btn-sm mt-2"><i class="fa-solid fa-right-from-bracket me-1"></i>Logout</button>
    `;
    const sbl = document.getElementById('sbLogout');
    if (sbl){ sbl.addEventListener('click', ()=>{ fetch('api/logout.php').finally(()=>location.replace('index.php')); }); }
  }

  })();
})();

// Helpers - Define Api globally outside the closure
window.Api = {
  async get(url){ const r = await fetch(url); if(!r.ok) throw new Error(await r.text()); return r.json(); },
  async send(url, method, body){ const r = await fetch(url,{method, headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)}); if(!r.ok) throw new Error(await r.text()); return r.json(); },
  q(obj){ const p = Object.entries(obj).filter(([,v])=>v!==undefined&&v!==null&&v!=='').map(([k,v])=>`${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&'); return p?('?'+p):''; },
  csv(filename, rows){
    if (!rows || !rows.length) return;
    const headers = Object.keys(rows[0]);
    const escape = v => ('"' + String(v ?? '').replace(/"/g,'""') + '"');
    const csv = [headers.map(escape).join(',')].concat(rows.map(r=>headers.map(h=>escape(r[h])).join(','))).join('\r\n');
    const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();
    URL.revokeObjectURL(link.href);
  }
};
