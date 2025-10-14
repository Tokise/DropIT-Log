<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>DropIT · ALMS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
  <main class="container-fluid py-3">
    <div class="row g-3">
      <aside class="col-12 col-md-3 col-lg-2">
        <div id="sidebar" class="card shadow-sm p-2"></div>
      </aside>
      <section class="col-12 col-md-9 col-lg-10">
        <!-- Assets Tab -->
        <div class="card shadow-sm mb-3">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <h5 class="card-title mb-0"><i class="fa-solid fa-screwdriver-wrench me-2 text-primary"></i>ALMS – Asset Registry</h5>
              <div class="d-flex gap-2">
                <select id="assetType" class="form-select form-select-sm" style="width:150px">
                  <option value="">All Types</option>
                  <option value="vehicle">Vehicle</option>
                  <option value="equipment">Equipment</option>
                  <option value="machinery">Machinery</option>
                  <option value="it_hardware">IT Hardware</option>
                  <option value="furniture">Furniture</option>
                </select>
                <select id="assetStatus" class="form-select form-select-sm" style="width:120px">
                  <option value="">All Status</option>
                  <option value="active">Active</option>
                  <option value="maintenance">Maintenance</option>
                  <option value="retired">Retired</option>
                </select>
                <input id="assetSearch" class="form-control form-control-sm" style="width:160px" placeholder="Search assets"/>
                <button id="filterAssetsBtn" class="btn btn-outline-primary btn-sm">Filter</button>
                <button id="createAssetBtn" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Add Asset</button>
                <button id="scanQRBtn" class="btn btn-success btn-sm"><i class="fa-solid fa-qrcode"></i> Scan QR</button>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Asset Code</th><th>Name</th><th>Type</th><th>Status</th><th>Location</th><th>Next Maintenance</th><th>Actions</th>
                  </tr>
                </thead>
                <tbody id="assetsRows"><tr><td colspan="7" class="text-muted">Loading assets...</td></tr></tbody>
              </table>
            </div>
            <div class="d-flex align-items-center gap-2">
              <button id="assetsPrev" class="btn btn-outline-secondary btn-sm">Prev</button>
              <button id="assetsNext" class="btn btn-outline-secondary btn-sm">Next</button>
              <span id="assetsMeta" class="text-muted small"></span>
            </div>
          </div>
        </div>

        <!-- Maintenance Tab -->
        <div class="card shadow-sm mb-3">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <h5 class="card-title mb-0"><i class="fa-regular fa-clipboard me-2 text-primary"></i>Maintenance Schedule</h5>
              <div class="d-flex gap-2">
                <select id="maintenanceStatus" class="form-select form-select-sm" style="width:150px">
                  <option value="">All Status</option>
                  <option value="scheduled">Scheduled</option>
                  <option value="overdue">Overdue</option>
                  <option value="in_progress">In Progress</option>
                  <option value="completed">Completed</option>
                </select>
                <button id="filterMaintenanceBtn" class="btn btn-outline-primary btn-sm">Filter</button>
                <button id="scheduleMaintenanceBtn" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Schedule</button>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Asset</th><th>Type</th><th>Title</th><th>Scheduled Date</th><th>Priority</th><th>Status</th><th>Actions</th>
                  </tr>
                </thead>
                <tbody id="maintenanceRows"><tr><td colspan="7" class="text-muted">Loading maintenance...</td></tr></tbody>
              </table>
            </div>
            <div class="d-flex align-items-center gap-2">
              <button id="maintenancePrev" class="btn btn-outline-secondary btn-sm">Prev</button>
              <button id="maintenanceNext" class="btn btn-outline-secondary btn-sm">Next</button>
              <span id="maintenanceMeta" class="text-muted small"></span>
            </div>
          </div>
        </div>

        <!-- Asset Detail Modal -->
        <div id="assetDetailCard" class="card shadow-sm d-none">
          <div class="card-body">
            <h5 class="card-title mb-3"><i class="fa-solid fa-info-circle me-2 text-primary"></i>Asset Details <span id="assetDetailId"></span></h5>
            <div id="assetDetail" class="row g-3"></div>
            <hr/>
            <h6><i class="fa-solid fa-wrench me-2 text-secondary"></i>Maintenance History</h6>
            <div class="table-responsive">
              <table class="table table-sm">
                <thead><tr><th>Date</th><th>Type</th><th>Status</th><th>Cost</th><th>Notes</th></tr></thead>
                <tbody id="maintenanceHistoryRows"></tbody>
              </table>
            </div>
          </div>
        </div>
      </section>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/common.js"></script>
  <script>
    let assetsPage = 1, maintenancePage = 1, limit = 10;
    let assetFilters = { asset_type: '', status: '', search: '' };
    let maintenanceFilters = { status: '' };

    // Load Assets
    async function loadAssets() {
      const params = { ...assetFilters, page: assetsPage, limit };
      const res = await Api.get(`api/alms_assets.php${Api.q(params)}`);
      const assets = res.data.assets || [];
      const total = res.data.pagination?.total || 0;
      
      document.getElementById('assetsRows').innerHTML = assets.map(a => {
        const statusColor = {
          'active': 'success', 'maintenance': 'warning', 
          'retired': 'secondary', 'disposed': 'dark'
        }[a.status] || 'secondary';
        
        const maintenanceDue = a.days_to_maintenance !== null ? 
          (a.days_to_maintenance < 0 ? 'Overdue' : 
           a.days_to_maintenance === 0 ? 'Due Today' : 
           `${a.days_to_maintenance} days`) : 'Not scheduled';
        
        return `<tr>
          <td><strong>${a.asset_code}</strong></td>
          <td>${a.asset_name}</td>
          <td><span class="badge text-bg-info">${a.asset_type}</span></td>
          <td><span class="badge text-bg-${statusColor}">${a.status}</span></td>
          <td>${a.location || a.warehouse_name || 'Not assigned'}</td>
          <td><small class="${a.days_to_maintenance < 0 ? 'text-danger' : a.days_to_maintenance <= 7 ? 'text-warning' : 'text-muted'}">${maintenanceDue}</small></td>
          <td>
            <button class="btn btn-sm btn-outline-primary" data-view-asset="${a.id}" title="View Details"><i class="fa-solid fa-eye"></i></button>
            <button class="btn btn-sm btn-outline-warning" data-maintenance="${a.id}" title="Schedule Maintenance"><i class="fa-solid fa-wrench"></i></button>
          </td>
        </tr>`;
      }).join('') || '<tr><td colspan="7" class="text-muted">No assets found</td></tr>';
      
      const pages = Math.max(1, Math.ceil(total / limit));
      document.getElementById('assetsPrev').disabled = assetsPage <= 1;
      document.getElementById('assetsNext').disabled = assetsPage >= pages;
      document.getElementById('assetsMeta').textContent = `Page ${assetsPage}/${pages} • ${total} assets`;
    }

    // Load Maintenance
    async function loadMaintenance() {
      const params = { ...maintenanceFilters, page: maintenancePage, limit };
      const res = await Api.get(`api/alms_maintenance.php${Api.q(params)}`);
      const maintenance = res.data.maintenance || [];
      const total = res.data.pagination?.total || 0;
      
      document.getElementById('maintenanceRows').innerHTML = maintenance.map(m => {
        const statusColor = {
          'scheduled': 'primary', 'overdue': 'danger', 
          'in_progress': 'warning', 'completed': 'success'
        }[m.status] || 'secondary';
        
        const priorityColor = {
          'low': 'secondary', 'medium': 'info', 
          'high': 'warning', 'critical': 'danger'
        }[m.priority] || 'secondary';
        
        return `<tr>
          <td><strong>${m.asset_code}</strong><br><small class="text-muted">${m.asset_name}</small></td>
          <td><span class="badge text-bg-info">${m.maintenance_type}</span></td>
          <td>${m.title}</td>
          <td>${new Date(m.scheduled_date).toLocaleDateString()}</td>
          <td><span class="badge text-bg-${priorityColor}">${m.priority}</span></td>
          <td><span class="badge text-bg-${statusColor}">${m.status}</span></td>
          <td>
            <button class="btn btn-sm btn-outline-success" data-complete="${m.id}" title="Complete"><i class="fa-solid fa-check"></i></button>
            <button class="btn btn-sm btn-outline-warning" data-reschedule="${m.id}" title="Reschedule"><i class="fa-solid fa-calendar"></i></button>
          </td>
        </tr>`;
      }).join('') || '<tr><td colspan="7" class="text-muted">No maintenance scheduled</td></tr>';
      
      const pages = Math.max(1, Math.ceil(total / limit));
      document.getElementById('maintenancePrev').disabled = maintenancePage <= 1;
      document.getElementById('maintenanceNext').disabled = maintenancePage >= pages;
      document.getElementById('maintenanceMeta').textContent = `Page ${maintenancePage}/${pages} • ${total} items`;
    }

    // Event Listeners
    document.getElementById('filterAssetsBtn').addEventListener('click', () => {
      assetFilters.asset_type = document.getElementById('assetType').value;
      assetFilters.status = document.getElementById('assetStatus').value;
      assetFilters.search = document.getElementById('assetSearch').value;
      assetsPage = 1;
      loadAssets();
    });

    document.getElementById('filterMaintenanceBtn').addEventListener('click', () => {
      maintenanceFilters.status = document.getElementById('maintenanceStatus').value;
      maintenancePage = 1;
      loadMaintenance();
    });

    document.getElementById('createAssetBtn').addEventListener('click', () => {
      const name = prompt('Asset Name:');
      if (!name) return;
      const type = prompt('Asset Type (vehicle/equipment/machinery/it_hardware/furniture):');
      if (!type) return;
      
      createAsset({
        asset_name: name,
        asset_type: type,
        category: 'General',
        status: 'active',
        purchase_date: new Date().toISOString().split('T')[0],
        purchase_cost: 0
      });
    });

    document.getElementById('scanQRBtn').addEventListener('click', () => {
      const code = prompt('Enter QR/Barcode:');
      if (code) scanAsset(code);
    });

    document.getElementById('scheduleMaintenanceBtn').addEventListener('click', () => {
      const assetId = prompt('Asset ID:');
      if (!assetId) return;
      const title = prompt('Maintenance Title:');
      if (!title) return;
      const date = prompt('Scheduled Date (YYYY-MM-DD):');
      if (!date) return;
      
      scheduleMaintenance({
        asset_id: parseInt(assetId),
        maintenance_type: 'preventive',
        title: title,
        scheduled_date: date,
        priority: 'medium'
      });
    });

    // Asset Actions
    document.getElementById('assetsRows').addEventListener('click', (e) => {
      const viewBtn = e.target.closest('button[data-view-asset]');
      const maintenanceBtn = e.target.closest('button[data-maintenance]');
      
      if (viewBtn) {
        const assetId = parseInt(viewBtn.getAttribute('data-view-asset'));
        viewAssetDetails(assetId);
      }
      
      if (maintenanceBtn) {
        const assetId = parseInt(maintenanceBtn.getAttribute('data-maintenance'));
        const title = prompt('Maintenance Title:');
        if (title) {
          const date = prompt('Scheduled Date (YYYY-MM-DD):');
          if (date) {
            scheduleMaintenance({
              asset_id: assetId,
              maintenance_type: 'preventive',
              title: title,
              scheduled_date: date,
              priority: 'medium'
            });
          }
        }
      }
    });

    // Maintenance Actions
    document.getElementById('maintenanceRows').addEventListener('click', (e) => {
      const completeBtn = e.target.closest('button[data-complete]');
      const rescheduleBtn = e.target.closest('button[data-reschedule]');
      
      if (completeBtn) {
        const maintenanceId = parseInt(completeBtn.getAttribute('data-complete'));
        completeMaintenance(maintenanceId);
      }
      
      if (rescheduleBtn) {
        const maintenanceId = parseInt(rescheduleBtn.getAttribute('data-reschedule'));
        const newDate = prompt('New Date (YYYY-MM-DD):');
        if (newDate) {
          rescheduleMaintenance(maintenanceId, newDate);
        }
      }
    });

    // Pagination
    document.getElementById('assetsPrev').addEventListener('click', () => {
      if (assetsPage > 1) { assetsPage--; loadAssets(); }
    });
    document.getElementById('assetsNext').addEventListener('click', () => {
      assetsPage++; loadAssets();
    });
    document.getElementById('maintenancePrev').addEventListener('click', () => {
      if (maintenancePage > 1) { maintenancePage--; loadMaintenance(); }
    });
    document.getElementById('maintenanceNext').addEventListener('click', () => {
      maintenancePage++; loadMaintenance();
    });

    // API Functions
    async function createAsset(data) {
      try {
        const res = await Api.send('api/alms_assets.php', 'POST', data);
        alert(`Asset created! Code: ${res.data.asset_code}`);
        loadAssets();
      } catch (e) {
        alert('Error creating asset');
      }
    }

    async function scanAsset(code) {
      try {
        const res = await Api.get(`api/alms_assets.php?action=scan&code=${code}`);
        viewAssetDetails(res.data.id);
      } catch (e) {
        alert('Asset not found');
      }
    }

    async function viewAssetDetails(assetId) {
      try {
        const res = await Api.get(`api/alms_assets.php?id=${assetId}`);
        const asset = res.data;
        
        document.getElementById('assetDetailCard').classList.remove('d-none');
        document.getElementById('assetDetailId').textContent = `#${asset.id}`;
        
        document.getElementById('assetDetail').innerHTML = `
          <div class="col-md-4"><div class="text-muted small">Asset Code</div><div><strong>${asset.asset_code}</strong></div></div>
          <div class="col-md-4"><div class="text-muted small">Name</div><div>${asset.asset_name}</div></div>
          <div class="col-md-4"><div class="text-muted small">Type</div><div>${asset.asset_type}</div></div>
          <div class="col-md-4"><div class="text-muted small">Status</div><div><span class="badge text-bg-success">${asset.status}</span></div></div>
          <div class="col-md-4"><div class="text-muted small">Location</div><div>${asset.location || 'Not assigned'}</div></div>
          <div class="col-md-4"><div class="text-muted small">Purchase Cost</div><div>₱${asset.purchase_cost?.toLocaleString() || '0'}</div></div>
          <div class="col-md-6"><div class="text-muted small">Manufacturer</div><div>${asset.manufacturer || 'N/A'}</div></div>
          <div class="col-md-6"><div class="text-muted small">Model</div><div>${asset.model || 'N/A'}</div></div>
        `;
        
        // Load maintenance history
        const history = asset.maintenance_history || [];
        document.getElementById('maintenanceHistoryRows').innerHTML = history.map(h => `<tr>
          <td>${new Date(h.scheduled_date).toLocaleDateString()}</td>
          <td>${h.maintenance_type}</td>
          <td><span class="badge text-bg-${h.status === 'completed' ? 'success' : 'warning'}">${h.status}</span></td>
          <td>₱${h.cost?.toLocaleString() || '0'}</td>
          <td><small>${h.notes || ''}</small></td>
        </tr>`).join('') || '<tr><td colspan="5" class="text-muted">No maintenance history</td></tr>';
        
      } catch (e) {
        Swal.fire('Error', 'Failed to load asset details', 'error');
      }
    }

    async function scheduleMaintenance(data) {
      try {
        const res = await Api.send('api/alms_maintenance.php', 'POST', data);
        Swal.fire('Success', 'Maintenance scheduled successfully', 'success');
        loadMaintenance();
      } catch (e) {
        Swal.fire('Error', 'Failed to schedule maintenance', 'error');
      }
    }

    async function completeMaintenance(maintenanceId) {
      try {
        const { value: formValues } = await Swal.fire({
          title: 'Complete Maintenance',
          html: `
            <div class="text-start">
              <div class="mb-3">
                <label class="form-label">Actual Cost</label>
                <input id="swal-cost" type="number" class="form-control" placeholder="0.00" step="0.01">
              </div>
              <div class="mb-3">
                <label class="form-label">Completion Notes</label>
                <textarea id="swal-notes" class="form-control" rows="3" placeholder="Enter completion notes..."></textarea>
              </div>
            </div>
          `,
          focusConfirm: false,
          showCancelButton: true,
          confirmButtonText: 'Complete',
          cancelButtonText: 'Cancel',
          preConfirm: () => {
            return {
              actual_cost: parseFloat(document.getElementById('swal-cost').value) || 0,
              notes: document.getElementById('swal-notes').value
            };
          }
        });

        if (formValues) {
          await Api.send('api/alms_maintenance.php?action=complete', 'PUT', {
            maintenance_id: maintenanceId,
            ...formValues
          });
          
          Swal.fire('Success', 'Maintenance completed successfully', 'success');
          loadMaintenance();
        }
      } catch (e) {
        Swal.fire('Error', 'Failed to complete maintenance', 'error');
      }
    }

    async function rescheduleMaintenance(maintenanceId, newDate) {
      try {
        await Api.send('api/alms_maintenance.php?action=reschedule', 'PUT', {
          maintenance_id: maintenanceId,
          new_date: newDate
        });
        
        Swal.fire('Success', 'Maintenance rescheduled successfully', 'success');
        loadMaintenance();
      } catch (e) {
        Swal.fire('Error', 'Failed to reschedule maintenance', 'error');
      }
    }

    // Initialize
    loadAssets();
    loadMaintenance();
  </script>
</body>
</html>
