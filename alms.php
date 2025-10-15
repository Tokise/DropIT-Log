<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>DropIT · ALMS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://unpkg.com/@zxing/library@latest/umd/index.min.js"></script>
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

    document.getElementById('createAssetBtn').addEventListener('click', async () => {
      const { value: formValues } = await Swal.fire({
        title: 'Create New Asset',
        html: `
          <div class="text-start">
            <div class="mb-3">
              <label class="form-label">Asset Name *</label>
              <input id="swal-name" type="text" class="form-control" placeholder="Enter asset name" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Asset Type *</label>
              <select id="swal-type" class="form-select" required>
                <option value="">Select type</option>
                <option value="vehicle">Vehicle</option>
                <option value="equipment">Equipment</option>
                <option value="machinery">Machinery</option>
                <option value="it_hardware">IT Hardware</option>
                <option value="furniture">Furniture</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Category</label>
              <input id="swal-category" type="text" class="form-control" placeholder="General" value="General">
            </div>
            <div class="mb-3">
              <label class="form-label">Purchase Cost</label>
              <input id="swal-cost" type="number" class="form-control" placeholder="0.00" step="0.01">
            </div>
            <div class="mb-3">
              <label class="form-label">Serial Number</label>
              <input id="swal-serial" type="text" class="form-control" placeholder="Enter serial number">
            </div>
          </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Create Asset',
        cancelButtonText: 'Cancel',
        preConfirm: () => {
          const name = document.getElementById('swal-name').value;
          const type = document.getElementById('swal-type').value;
          
          if (!name || !type) {
            Swal.showValidationMessage('Please fill in required fields');
            return false;
          }
          
          return {
            asset_name: name,
            asset_type: type,
            category: document.getElementById('swal-category').value || 'General',
            purchase_cost: parseFloat(document.getElementById('swal-cost').value) || 0,
            serial_number: document.getElementById('swal-serial').value,
            status: 'active',
            purchase_date: new Date().toISOString().split('T')[0]
          };
        }
      });

      if (formValues) {
        createAsset(formValues);
      }
    });

    document.getElementById('scanQRBtn').addEventListener('click', async () => {
      const { value: scanMethod } = await Swal.fire({
        title: 'Scan Asset Code',
        html: `
          <div class="text-start">
            <div class="mb-3">
              <button id="camera-scan-btn" class="btn btn-primary w-100 mb-2">
                <i class="fas fa-camera me-2"></i>Use Camera
              </button>
              <button id="manual-input-btn" class="btn btn-outline-secondary w-100">
                <i class="fas fa-keyboard me-2"></i>Manual Input
              </button>
            </div>
            <div id="camera-container" class="d-none">
              <video id="camera-preview" class="w-100" style="max-height: 300px;" autoplay></video>
              <div class="mt-2 text-center">
                <button id="stop-camera-btn" class="btn btn-sm btn-danger">Stop Camera</button>
              </div>
            </div>
            <div id="manual-container" class="d-none">
              <input id="manual-code-input" type="text" class="form-control" placeholder="Enter asset code, barcode, or QR code">
            </div>
          </div>
        `,
        showConfirmButton: false,
        showCancelButton: true,
        cancelButtonText: 'Close',
        didOpen: () => {
          setupBarcodeScanner();
        },
        willClose: () => {
          stopCamera();
        }
      });
    });

    document.getElementById('scheduleMaintenanceBtn').addEventListener('click', async () => {
      const { value: formValues } = await Swal.fire({
        title: 'Schedule Maintenance',
        html: `
          <div class="text-start">
            <div class="mb-3">
              <label class="form-label">Asset ID *</label>
              <input id="swal-asset-id" type="number" class="form-control" placeholder="Enter asset ID" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Maintenance Title *</label>
              <input id="swal-title" type="text" class="form-control" placeholder="e.g., Monthly inspection" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Maintenance Type</label>
              <select id="swal-type" class="form-select">
                <option value="preventive">Preventive</option>
                <option value="corrective">Corrective</option>
                <option value="emergency">Emergency</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Scheduled Date *</label>
              <input id="swal-date" type="date" class="form-control" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Priority</label>
              <select id="swal-priority" class="form-select">
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
                <option value="critical">Critical</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Estimated Cost</label>
              <input id="swal-cost" type="number" class="form-control" placeholder="0.00" step="0.01">
            </div>
          </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Schedule',
        cancelButtonText: 'Cancel',
        preConfirm: () => {
          const assetId = document.getElementById('swal-asset-id').value;
          const title = document.getElementById('swal-title').value;
          const date = document.getElementById('swal-date').value;
          
          if (!assetId || !title || !date) {
            Swal.showValidationMessage('Please fill in required fields');
            return false;
          }
          
          return {
            asset_id: parseInt(assetId),
            title: title,
            maintenance_type: document.getElementById('swal-type').value,
            scheduled_date: date,
            priority: document.getElementById('swal-priority').value,
            estimated_cost: parseFloat(document.getElementById('swal-cost').value) || 0
          };
        }
      });

      if (formValues) {
        scheduleMaintenance(formValues);
      }
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
        scheduleMaintenanceForAsset(assetId);
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
        rescheduleMaintenanceWithSwal(maintenanceId);
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

    // Barcode Scanner Functions
    let codeReader = null;
    let currentStream = null;

    function setupBarcodeScanner() {
      document.getElementById('camera-scan-btn').addEventListener('click', startCamera);
      document.getElementById('manual-input-btn').addEventListener('click', showManualInput);
      document.getElementById('stop-camera-btn').addEventListener('click', stopCamera);
      
      // Handle manual input
      document.getElementById('manual-code-input').addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
          const code = e.target.value.trim();
          if (code) {
            Swal.close();
            scanAsset(code);
          }
        }
      });
    }

    async function startCamera() {
      try {
        const cameraContainer = document.getElementById('camera-container');
        const videoElement = document.getElementById('camera-preview');
        
        cameraContainer.classList.remove('d-none');
        
        // Initialize ZXing code reader
        codeReader = new ZXing.BrowserMultiFormatReader();
        
        // Get camera stream
        const stream = await navigator.mediaDevices.getUserMedia({ 
          video: { facingMode: 'environment' } // Use back camera if available
        });
        
        currentStream = stream;
        videoElement.srcObject = stream;
        
        // Start decoding
        codeReader.decodeFromVideoDevice(null, videoElement, (result, err) => {
          if (result) {
            stopCamera();
            Swal.close();
            scanAsset(result.text);
          }
        });
        
      } catch (error) {
        console.error('Camera error:', error);
        Swal.fire('Camera Error', 'Unable to access camera. Please use manual input.', 'error');
        showManualInput();
      }
    }

    function showManualInput() {
      document.getElementById('camera-container').classList.add('d-none');
      document.getElementById('manual-container').classList.remove('d-none');
      document.getElementById('manual-code-input').focus();
    }

    function stopCamera() {
      if (currentStream) {
        currentStream.getTracks().forEach(track => track.stop());
        currentStream = null;
      }
      if (codeReader) {
        codeReader.reset();
        codeReader = null;
      }
    }

    // API Functions
    async function createAsset(data) {
      try {
        const res = await Api.send('api/alms_assets.php', 'POST', data);
        await Swal.fire({
          title: 'Success!',
          html: `Asset created successfully!<br><strong>Asset Code:</strong> ${res.data.asset_code}`,
          icon: 'success',
          confirmButtonText: 'OK'
        });
        loadAssets();
      } catch (e) {
        Swal.fire('Error', 'Failed to create asset: ' + e.message, 'error');
      }
    }

    async function scanAsset(code) {
      try {
        Swal.fire({
          title: 'Scanning...',
          text: `Looking for asset: ${code}`,
          allowOutsideClick: false,
          didOpen: () => {
            Swal.showLoading();
          }
        });
        
        const res = await Api.get(`api/alms_assets.php?action=scan&code=${code}`);
        Swal.close();
        
        await Swal.fire({
          title: 'Asset Found!',
          html: `
            <div class="text-start">
              <p><strong>Asset Code:</strong> ${res.data.asset_code}</p>
              <p><strong>Name:</strong> ${res.data.asset_name}</p>
              <p><strong>Type:</strong> ${res.data.asset_type}</p>
              <p><strong>Status:</strong> <span class="badge bg-success">${res.data.status}</span></p>
            </div>
          `,
          icon: 'success',
          confirmButtonText: 'View Details'
        });
        
        viewAssetDetails(res.data.id);
      } catch (e) {
        Swal.fire({
          title: 'Asset Not Found',
          text: `No asset found with code: ${code}`,
          icon: 'warning',
          confirmButtonText: 'OK'
        });
      }
    }

    async function viewAssetDetails(assetId) {
      try {
        // Show loading
        Swal.fire({
          title: 'Loading Asset Details...',
          allowOutsideClick: false,
          didOpen: () => {
            Swal.showLoading();
          }
        });

        const res = await Api.get(`api/alms_assets.php?id=${assetId}`);
        const asset = res.data;
        
        // Close loading and show asset details
        Swal.close();
        
        const statusColor = {
          'active': 'success',
          'maintenance': 'warning', 
          'retired': 'secondary',
          'disposed': 'danger'
        }[asset.status] || 'secondary';

        const history = asset.maintenance_history || [];
        const maintenanceHistoryHtml = history.length > 0 
          ? history.map(h => `
              <tr>
                <td>${new Date(h.scheduled_date).toLocaleDateString()}</td>
                <td><span class="badge bg-info">${h.maintenance_type}</span></td>
                <td><span class="badge bg-${h.status === 'completed' ? 'success' : 'warning'}">${h.status}</span></td>
                <td>₱${h.cost?.toLocaleString() || '0'}</td>
                <td><small>${h.notes || '-'}</small></td>
              </tr>
            `).join('')
          : '<tr><td colspan="5" class="text-center text-muted">No maintenance history</td></tr>';

        await Swal.fire({
          title: `Asset Details - ${asset.asset_code}`,
          html: `
            <div class="text-start">
              <div class="row g-3 mb-4">
                <div class="col-md-6">
                  <div class="card h-100">
                    <div class="card-header bg-primary text-white">
                      <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Basic Information</h6>
                    </div>
                    <div class="card-body">
                      <div class="row g-2">
                        <div class="col-12"><strong>Name:</strong> ${asset.asset_name}</div>
                        <div class="col-6"><strong>Type:</strong> ${asset.asset_type}</div>
                        <div class="col-6"><strong>Status:</strong> <span class="badge bg-${statusColor}">${asset.status}</span></div>
                        <div class="col-6"><strong>Category:</strong> ${asset.category || 'N/A'}</div>
                        <div class="col-6"><strong>Serial:</strong> ${asset.serial_number || 'N/A'}</div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="card h-100">
                    <div class="card-header bg-success text-white">
                      <h6 class="mb-0"><i class="fas fa-dollar-sign me-2"></i>Financial Information</h6>
                    </div>
                    <div class="card-body">
                      <div class="row g-2">
                        <div class="col-12"><strong>Purchase Cost:</strong> ₱${asset.purchase_cost?.toLocaleString() || '0'}</div>
                        <div class="col-12"><strong>Current Value:</strong> ₱${asset.current_value?.toLocaleString() || '0'}</div>
                        <div class="col-12"><strong>Purchase Date:</strong> ${asset.purchase_date ? new Date(asset.purchase_date).toLocaleDateString() : 'N/A'}</div>
                        <div class="col-12"><strong>Warranty:</strong> ${asset.warranty_expiry ? new Date(asset.warranty_expiry).toLocaleDateString() : 'N/A'}</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              
              <div class="card">
                <div class="card-header bg-warning text-dark">
                  <h6 class="mb-0"><i class="fas fa-tools me-2"></i>Maintenance History</h6>
                </div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-sm mb-0">
                      <thead class="table-light">
                        <tr>
                          <th>Date</th>
                          <th>Type</th>
                          <th>Status</th>
                          <th>Cost</th>
                          <th>Notes</th>
                        </tr>
                      </thead>
                      <tbody>
                        ${maintenanceHistoryHtml}
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          `,
          width: '800px',
          showCancelButton: true,
          confirmButtonText: '<i class="fas fa-tools me-2"></i>Schedule Maintenance',
          cancelButtonText: 'Close',
          confirmButtonColor: '#28a745'
        }).then((result) => {
          if (result.isConfirmed) {
            scheduleMaintenanceForAsset(assetId);
          }
        });
        
      } catch (e) {
        Swal.fire('Error', 'Failed to load asset details: ' + e.message, 'error');
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

    async function scheduleMaintenanceForAsset(assetId) {
      const { value: formValues } = await Swal.fire({
        title: 'Schedule Maintenance',
        html: `
          <div class="text-start">
            <div class="mb-3">
              <label class="form-label">Asset ID</label>
              <input id="swal-asset-id" type="number" class="form-control" value="${assetId}" readonly>
            </div>
            <div class="mb-3">
              <label class="form-label">Maintenance Title *</label>
              <input id="swal-title" type="text" class="form-control" placeholder="e.g., Monthly inspection" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Maintenance Type</label>
              <select id="swal-type" class="form-select">
                <option value="preventive" selected>Preventive</option>
                <option value="corrective">Corrective</option>
                <option value="emergency">Emergency</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Scheduled Date *</label>
              <input id="swal-date" type="date" class="form-control" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Priority</label>
              <select id="swal-priority" class="form-select">
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
                <option value="critical">Critical</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Estimated Cost</label>
              <input id="swal-cost" type="number" class="form-control" placeholder="0.00" step="0.01">
            </div>
          </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Schedule',
        cancelButtonText: 'Cancel',
        preConfirm: () => {
          const title = document.getElementById('swal-title').value;
          const date = document.getElementById('swal-date').value;
          
          if (!title || !date) {
            Swal.showValidationMessage('Please fill in required fields');
            return false;
          }
          
          return {
            asset_id: assetId,
            title: title,
            maintenance_type: document.getElementById('swal-type').value,
            scheduled_date: date,
            priority: document.getElementById('swal-priority').value,
            estimated_cost: parseFloat(document.getElementById('swal-cost').value) || 0
          };
        }
      });

      if (formValues) {
        scheduleMaintenance(formValues);
      }
    }

    async function rescheduleMaintenanceWithSwal(maintenanceId) {
      const { value: newDate } = await Swal.fire({
        title: 'Reschedule Maintenance',
        html: `
          <div class="text-start">
            <div class="mb-3">
              <label class="form-label">New Scheduled Date *</label>
              <input id="swal-new-date" type="date" class="form-control" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Reason for Rescheduling</label>
              <textarea id="swal-reason" class="form-control" rows="3" placeholder="Optional reason for rescheduling..."></textarea>
            </div>
          </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Reschedule',
        cancelButtonText: 'Cancel',
        preConfirm: () => {
          const date = document.getElementById('swal-new-date').value;
          
          if (!date) {
            Swal.showValidationMessage('Please select a new date');
            return false;
          }
          
          return {
            new_date: date,
            reason: document.getElementById('swal-reason').value
          };
        }
      });

      if (newDate) {
        rescheduleMaintenance(maintenanceId, newDate.new_date, newDate.reason);
      }
    }

    async function rescheduleMaintenance(maintenanceId, newDate, reason = null) {
      try {
        await Api.send('api/alms_maintenance.php?action=reschedule', 'PUT', {
          maintenance_id: maintenanceId,
          new_date: newDate,
          reason: reason
        });
        
        Swal.fire('Success', 'Maintenance rescheduled successfully', 'success');
        loadMaintenance();
      } catch (e) {
        Swal.fire('Error', 'Failed to reschedule maintenance', 'error');
      }
    }

    // Delete Asset Function
    async function deleteAsset(assetId) {
      const result = await Swal.fire({
        title: 'Delete Asset?',
        text: 'This action cannot be undone. The asset will be marked as disposed.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel'
      });

      if (result.isConfirmed) {
        try {
          await Api.send(`api/alms_assets.php?id=${assetId}`, 'DELETE');
          Swal.fire('Deleted!', 'Asset has been marked as disposed.', 'success');
          loadAssets();
        } catch (e) {
          Swal.fire('Error', 'Failed to delete asset: ' + e.message, 'error');
        }
      }
    }

    // Enhanced Loading Functions
    async function loadAssets() {
      try {
        document.getElementById('assetsRows').innerHTML = '<tr><td colspan="7" class="text-center"><div class="spinner-border spinner-border-sm me-2"></div>Loading assets...</td></tr>';
        
        const params = new URLSearchParams({
          page: assetsPage,
          limit: 10,
          ...assetFilters
        });
        
        const response = await Api.get(`api/alms_assets.php?${params}`);
        const assets = response.data.assets || [];
        const pagination = response.data.pagination || {};
        
        if (assets.length === 0) {
          document.getElementById('assetsRows').innerHTML = '<tr><td colspan="7" class="text-center text-muted">No assets found</td></tr>';
          return;
        }
        
        document.getElementById('assetsRows').innerHTML = assets.map(asset => `
          <tr>
            <td><code>${asset.asset_code}</code></td>
            <td>${asset.asset_name}</td>
            <td><span class="badge bg-info">${asset.asset_type}</span></td>
            <td><span class="badge bg-${asset.status === 'active' ? 'success' : asset.status === 'maintenance' ? 'warning' : 'secondary'}">${asset.status}</span></td>
            <td>${asset.location || asset.warehouse_name || 'Not assigned'}</td>
            <td>${asset.next_maintenance_date ? new Date(asset.next_maintenance_date).toLocaleDateString() : 'Not scheduled'}</td>
            <td>
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" data-view-asset="${asset.id}" title="View Details">
                  <i class="fas fa-eye"></i>
                </button>
                <button class="btn btn-outline-success" data-maintenance="${asset.id}" title="Schedule Maintenance">
                  <i class="fas fa-tools"></i>
                </button>
                <button class="btn btn-outline-danger" onclick="deleteAsset(${asset.id})" title="Delete Asset">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
            </td>
          </tr>
        `).join('');
        
        document.getElementById('assetsMeta').textContent = `Page ${pagination.page} of ${pagination.pages} (${pagination.total} total)`;
        
      } catch (error) {
        console.error('Error loading assets:', error);
        document.getElementById('assetsRows').innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error loading assets</td></tr>';
      }
    }

    async function loadMaintenance() {
      try {
        document.getElementById('maintenanceRows').innerHTML = '<tr><td colspan="7" class="text-center"><div class="spinner-border spinner-border-sm me-2"></div>Loading maintenance...</td></tr>';
        
        const params = new URLSearchParams({
          page: maintenancePage,
          limit: 10,
          ...maintenanceFilters
        });
        
        const response = await Api.get(`api/alms_maintenance.php?${params}`);
        const maintenance = response.data.maintenance || [];
        const pagination = response.data.pagination || {};
        
        if (maintenance.length === 0) {
          document.getElementById('maintenanceRows').innerHTML = '<tr><td colspan="7" class="text-center text-muted">No maintenance scheduled</td></tr>';
          return;
        }
        
        document.getElementById('maintenanceRows').innerHTML = maintenance.map(m => `
          <tr>
            <td>${m.asset_code} - ${m.asset_name}</td>
            <td><span class="badge bg-info">${m.maintenance_type}</span></td>
            <td>${m.title}</td>
            <td>${new Date(m.scheduled_date).toLocaleDateString()}</td>
            <td><span class="badge bg-${m.priority === 'high' ? 'danger' : m.priority === 'medium' ? 'warning' : 'success'}">${m.priority}</span></td>
            <td><span class="badge bg-${m.status === 'completed' ? 'success' : m.status === 'overdue' ? 'danger' : 'warning'}">${m.status}</span></td>
            <td>
              <div class="btn-group btn-group-sm">
                ${m.status !== 'completed' ? `
                  <button class="btn btn-outline-success" data-complete="${m.id}" title="Complete">
                    <i class="fas fa-check"></i>
                  </button>
                  <button class="btn btn-outline-warning" data-reschedule="${m.id}" title="Reschedule">
                    <i class="fas fa-calendar"></i>
                  </button>
                ` : `
                  <span class="text-success"><i class="fas fa-check-circle"></i> Completed</span>
                `}
              </div>
            </td>
          </tr>
        `).join('');
        
        document.getElementById('maintenanceMeta').textContent = `Page ${pagination.page} of ${pagination.pages} (${pagination.total} total)`;
        
      } catch (error) {
        console.error('Error loading maintenance:', error);
        document.getElementById('maintenanceRows').innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error loading maintenance</td></tr>';
      }
    }

    // Initialize with loading animation
    Swal.fire({
      title: 'Loading ALMS...',
      text: 'Initializing Asset Lifecycle Management System',
      allowOutsideClick: false,
      didOpen: () => {
        Swal.showLoading();
      }
    });

    // Load data and close loading
    Promise.all([loadAssets(), loadMaintenance()])
      .then(() => {
        Swal.close();
      })
      .catch(() => {
        Swal.fire('Error', 'Failed to initialize ALMS', 'error');
      });
  </script>
</body>
</html>
