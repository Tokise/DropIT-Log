<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>DropIT · SWS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .nav-pills .nav-link.active { background-color: #0d6efd; }
    .location-tree { max-height: 400px; overflow-y: auto; }
    .location-item { padding: 8px 12px; border-left: 3px solid #e9ecef; margin: 2px 0; }
    .location-item.zone { border-left-color: #007bff; background-color: #f8f9fa; }
    .location-item.aisle { border-left-color: #28a745; margin-left: 20px; }
    .location-item.rack { border-left-color: #ffc107; margin-left: 40px; }
    .location-item.bin { border-left-color: #dc3545; margin-left: 60px; }
    .location-item:hover { background-color: #e9ecef; cursor: pointer; }
    .location-item.selected { background-color: #d1ecf1; border-left-color: #17a2b8; }
    .capacity-bar { height: 8px; background-color: #e9ecef; border-radius: 4px; overflow: hidden; }
    .capacity-fill { height: 100%; background-color: #28a745; transition: width 0.3s ease; }
    .capacity-fill.warning { background-color: #ffc107; }
    .capacity-fill.danger { background-color: #dc3545; }
    .warehouse-map { 
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      border-radius: 8px; 
      padding: 20px; 
      min-height: 400px;
      position: relative;
    }
    .zone-box { 
      background: white;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 4px 15px rgba(0,0,0,0.1);
      transition: all 0.3s ease;
      cursor: pointer;
    }
    .zone-box:hover {
      transform: translateY(-5px);
      box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    }
    .zone-box.storage { border-left: 5px solid #28a745; }
    .zone-box.receiving { border-left: 5px solid #007bff; }
    .zone-box.shipping { border-left: 5px solid #ffc107; }
    .location-badge.occupied {
      background: #d4edda;
      border-color: #28a745;
    }
    .location-badge.full {
      background: #f8d7da;
      border-color: #dc3545;
    }
  </style>
</head>
<body>
  <main class="container-fluid py-3">
    <div class="row g-3">
      <aside class="col-12 col-md-3 col-lg-2">
        <div id="sidebar" class="card shadow-sm p-2">
          <div id="notifications-badge" class="position-relative mb-2">
            <button class="btn btn-outline-primary w-100" onclick="showNotifications()">
              <i class="fa-solid fa-bell me-2"></i>Notifications
              <span id="unread-count" class="badge bg-danger position-absolute top-0 start-100 translate-middle rounded-pill" style="display: none;">0</span>
            </button>
          </div>
        </div>
      </aside>
      <section class="col-12 col-md-9 col-lg-10">
        <div class="card shadow-sm">
          <div class="card-body">
          
            <div class="d-flex justify-content-between align-items-center mb-5">
              <h5 class="card-title mb-0"><i class="fa-solid fa-warehouse me-2 text-primary"></i>SWS – Smart Warehousing System</h5>
              <div class="btn-group">
                <button class="btn btn-outline-primary" onclick="openBarcodeScanner()" title="Barcode Scanner">
                  <i class="fa-solid fa-barcode me-1"></i>Scan Barcode
                </button>
                <button class="btn btn-outline-success" onclick="SWS.detectAnomalies()" title="AI Anomaly Detection">
                  <i class="fa-solid fa-robot me-1"></i>AI Analysis
                </button>
              </div>
            </div>
            
            <!-- Navigation Tabs -->
            <ul class="nav nav-pills mb-4" id="swsTabs" role="tablist" style="margin-top: 2rem;">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="locations-tab" data-bs-toggle="pill" data-bs-target="#locations" type="button" role="tab">
                  <i class="fa-solid fa-map-location-dot me-1"></i>Location Management
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="inventory-tab" data-bs-toggle="pill" data-bs-target="#inventory" type="button" role="tab">
                  <i class="fa-solid fa-boxes-stacked me-1"></i>Inventory
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="products-tab" data-bs-toggle="pill" data-bs-target="#products" type="button" role="tab">
                  <i class="fa-solid fa-cube me-1"></i>Products
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="movements-tab" data-bs-toggle="pill" data-bs-target="#movements" type="button" role="tab">
                  <i class="fa-solid fa-arrows-rotate me-1"></i>Stock Movements
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="shipments-tab" data-bs-toggle="pill" data-bs-target="#shipments" type="button" role="tab">
                  <i class="fa-solid fa-truck me-1"></i>Shipments
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="warehouses-tab" data-bs-toggle="pill" data-bs-target="#warehouses" type="button" role="tab">
                  <i class="fa-solid fa-building me-1"></i>Warehouses
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="ai-tools-tab" data-bs-toggle="pill" data-bs-target="#ai-tools" type="button" role="tab">
                  <i class="fa-solid fa-robot me-1"></i>AI Tools
                </button>
              </li>
            </ul>



            <!-- Tab Content -->
            <div class="tab-content" id="swsTabContent">
              <div class="tab-content" id="swsTabContent">
                <!-- Location Management Tab -->
                <div class="tab-pane fade show active" id="locations" role="tabpanel">
                  <!-- Dropdown Zone Selector -->
                  <div class="card mb-3">
                    <div class="card-body">
                      <div class="row align-items-center">
                        <div class="col-md-4">
                          <label class="form-label mb-1"><strong>Select Zone:</strong></label>
                          <select id="zoneSelector" class="form-select" onchange="selectZone(this.value)">
                            <option value="">-- Select a Zone --</option>
                          </select>
                        </div>
                        <div class="col-md-8 text-end">
                          <button class="btn btn-primary" onclick="addZone()">
                            <i class="fa-solid fa-plus me-1"></i>Add Zone
                          </button>
                          <button class="btn btn-success" onclick="addLocationToCurrentZone()">
                            <i class="fa-solid fa-location-dot me-1"></i>Add Location
                          </button>
                          <button class="btn btn-outline-secondary" onclick="loadLocationTree()">
                            <i class="fa-solid fa-rotate me-1"></i>Refresh
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Horizontal Location Cards -->
                  <div class="card">
                    <div class="card-header">
                      <h6 class="mb-0"><i class="fa-solid fa-map-location-dot me-2"></i>Locations in <span id="currentZoneName">Selected Zone</span></h6>
                    </div>
                    <div class="card-body">
                      <div id="locationCards" class="row g-3">
                        <div class="col-12 text-center text-muted py-5">
                          <i class="fa-solid fa-warehouse fa-3x mb-3"></i>
                          <p>Select a zone from the dropdown above to view locations</p>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Warehouse Map -->
                  <div class="card mt-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                      <h6 class="mb-0"><i class="fa-solid fa-map me-2"></i>Warehouse Overview</h6>
                      <button class="btn btn-sm btn-outline-secondary" onclick="refreshMap()">
                        <i class="fa-solid fa-refresh"></i> Refresh
                      </button>
                    </div>
                    <div class="card-body">
                      <div id="warehouseMap" class="warehouse-map">
                        <div class="text-center p-5">
                          <i class="fa-solid fa-map text-muted" style="font-size: 3rem;"></i>
                          <p class="text-muted mt-3">Loading warehouse map...</p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <!-- Inventory Tab -->
                <div class="tab-pane fade" id="inventory" role="tabpanel">
                  <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0"><i class="fa-solid fa-boxes-stacked me-2"></i>Inventory by Location</h6>
                    <div class="d-flex gap-2">
                      <select id="warehouseFilter" class="form-select form-select-sm" style="width: 200px;">
                        <option value="">All Warehouses</option>
                      </select>
                      <input id="searchInventory" class="form-control form-control-sm" placeholder="Search products..." style="width: 200px;">
                      <button class="btn btn-outline-primary btn-sm" onclick="loadInventory()">
                        <i class="fa-solid fa-search"></i> Search
                      </button>
                    </div>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-hover">
                      <thead class="table-light">
                        <tr>
                          <th>Product</th>
                          <th>SKU</th>
                          <th>Location</th>
                          <th>Quantity</th>
                          <th>Reserved</th>
                          <th>Available</th>
                          <th>Batch</th>
                          <th>Expiry</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody id="inventoryTable">
                        <tr>
                          <td colspan="9" class="text-center text-muted">
                            <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                            Loading inventory...
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
                <!-- Products Tab -->
                <div class="tab-pane fade" id="products" role="tabpanel">
                  <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0"><i class="fa-solid fa-cube me-2"></i>Product Catalog</h6>
                    <button class="btn btn-primary btn-sm" onclick="addProduct()">
                      <i class="fa-solid fa-plus"></i> Add Product
                    </button>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-hover">
                      <thead class="table-light">
                        <tr>
                          <th>SKU</th>
                          <th>Name</th>
                          <th>Category</th>
                          <th>Price</th>
                          <th>Inventory</th>
                          <th>Status</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody id="productsTable">
                        <tr>
                          <td colspan="7" class="text-center text-muted">
                            <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                            Loading products...
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
                <!-- Stock Movements Tab -->
                <div class="tab-pane fade" id="movements" role="tabpanel">
                  <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0"><i class="fa-solid fa-arrows-rotate me-2"></i>Stock Movements</h6>
                    <div class="d-flex gap-2">
                      <select id="movementType" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        <option value="receipt">Receipt</option>
                        <option value="transfer">Transfer</option>
                        <option value="adjustment">Adjustment</option>
                        <option value="sale">Sale</option>
                      </select>
                      <button class="btn btn-primary btn-sm" onclick="addMovement()">
                        <i class="fa-solid fa-plus"></i> Add Movement
                      </button>
                    </div>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-hover">
                      <thead class="table-light">
                        <tr>
                          <th>Date</th>
                          <th>Product</th>
                          <th>Type</th>
                          <th>From</th>
                          <th>To</th>
                          <th>Quantity</th>
                          <th>Reason</th>
                          <th>User</th>
                        </tr>
                      </thead>
                      <tbody id="movementsTable">
                        <tr>
                          <td colspan="8" class="text-center text-muted">
                            <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                            Loading movements...
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
                <!-- Shipments Tab -->
                <div class="tab-pane fade" id="shipments" role="tabpanel">
                  <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0"><i class="fa-solid fa-truck me-2"></i>Incoming Shipments / Purchase Orders</h6>
                    <button class="btn btn-outline-primary btn-sm" onclick="SWS.loadShipments()">
                      <i class="fa-solid fa-rotate"></i> Refresh
                    </button>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-hover mb-0">
                      <thead class="table-light">
                        <tr>
                          <th>PO Number</th>
                          <th>Supplier</th>
                          <th>Status</th>
                          <th>Warehouse</th>
                          <th>Order Date</th>
                          <th>Expected Delivery</th>
                          <th>Total</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody id="shipmentsTable">
                        <tr>
                          <td colspan="8" class="text-center text-muted">
                            <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                            Loading shipments...
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
                <!-- Warehouses Tab -->
                <div class="tab-pane fade" id="warehouses" role="tabpanel">
                  <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0"><i class="fa-solid fa-building me-2"></i>Warehouse Management</h6>
                    <button class="btn btn-primary btn-sm" onclick="addWarehouse()">
                      <i class="fa-solid fa-plus"></i> Add Warehouse
                    </button>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-hover">
                      <thead class="table-light">
                        <tr>
                          <th>Code</th>
                          <th>Name</th>
                          <th>Location</th>
                          <th>Manager</th>
                          <th>Zones</th>
                          <th>Products</th>
                          <th>Total Inventory</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody id="warehousesTable">
                        <tr>
                          <td colspan="8" class="text-center text-muted">
                            <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                            Loading warehouses...
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
                <!-- AI Tools Tab -->
                <div class="tab-pane fade" id="ai-tools" role="tabpanel">
                  <div class="row">
                    <div class="col-md-6">
                      <div class="card mb-3">
                        <div class="card-header">
                          <h6 class="mb-0"><i class="fa-solid fa-brain me-2"></i>AI-Powered Features</h6>
                        </div>
                        <div class="card-body">
                          <div class="list-group">
                            <button class="list-group-item list-group-item-action" onclick="openBarcodeScanner()">
                              <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1"><i class="fa-solid fa-barcode me-2"></i>Barcode Scanner</h6>
                                <small class="text-muted">Quick scan</small>
                              </div>
                              <p class="mb-1 small">Scan barcodes with AI-powered context analysis</p>
                            </button>
                            <button class="list-group-item list-group-item-action" onclick="SWS.detectAnomalies()">
                              <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1"><i class="fa-solid fa-magnifying-glass-chart me-2"></i>Anomaly Detection</h6>
                                <small class="text-success">AI-powered</small>
                              </div>
                              <p class="mb-1 small">Detect inventory issues and unusual patterns</p>
                            </button>
                            <button class="list-group-item list-group-item-action" onclick="showAILocationDemo()">
                              <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1"><i class="fa-solid fa-map-location-dot me-2"></i>Smart Location Assignment</h6>
                                <small class="text-success">AI-powered</small>
                              </div>
                              <p class="mb-1 small">Let AI find optimal storage locations</p>
                            </button>
                            <button class="list-group-item list-group-item-action" onclick="showAIDemandDemo()">
                              <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1"><i class="fa-solid fa-chart-line me-2"></i>Demand Forecasting</h6>
                                <small class="text-success">AI-powered</small>
                              </div>
                              <p class="mb-1 small">Predict future inventory demand</p>
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="card mb-3">
                        <div class="card-header">
                          <h6 class="mb-0"><i class="fa-solid fa-info-circle me-2"></i>AI Configuration</h6>
                        </div>
                        <div class="card-body">
                          <div id="aiHealthStatus" class="mb-3">
                            <div class="spinner-border spinner-border-sm me-2"></div>
                            Checking AI service...
                          </div>
                          <div class="alert alert-info">
                            <strong>AI Features:</strong>
                            <ul class="mb-0 mt-2">
                              <li>Smart location assignment using Gemini AI</li>
                              <li>Demand forecasting based on historical data</li>
                              <li>Anomaly detection for inventory issues</li>
                              <li>Barcode context analysis</li>
                              <li>Optimal picking route generation</li>
                            </ul>
                          </div>
                          <div class="alert alert-warning">
                            <strong>Requirements:</strong><br>
                            <small>Ensure GEMINI_API_KEY and BARCODE_API_KEY are configured in .env file</small>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
        </div>
      
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/common.js"></script>
  <script src="assets/app.js"></script>
  <script src="assets/sws.js"></script>
  <script>
    let currentLocationType = '';
    let currentLocationId = null;
    let selectedLocation = null;

    // Initialize the page
    document.addEventListener('DOMContentLoaded', function() {
      loadLocationTree();
      loadInventory();
      loadProducts();
      loadMovements();
      loadWarehouses();
      loadWarehousesList();
    });

    let currentZoneId = null;
    let allZones = [];

    // Load warehouse location tree (DROPDOWN VERSION)
    async function loadLocationTree() {
      try {
        const response = await Api.get('api/sws_locations.php?action=tree&warehouse_id=1');
        allZones = response.data.zones || [];
        
        // Populate zone dropdown
        const zoneSelector = document.getElementById('zoneSelector');
        zoneSelector.innerHTML = '<option value="">-- Select a Zone --</option>';
        
        allZones.forEach(zone => {
          const option = document.createElement('option');
          option.value = zone.id;
          option.textContent = `${zone.zone_code} - ${zone.zone_name} (${zone.zone_type})`;
          zoneSelector.appendChild(option);
        });
        
        // Render warehouse map
        renderWarehouseMap(allZones);
        
        // If a zone was previously selected, reselect it
        if (currentZoneId) {
          zoneSelector.value = currentZoneId;
          selectZone(currentZoneId);
        }
      } catch (error) {
        console.error('Error loading location tree:', error);
        Swal.fire('Error', 'Failed to load zones: ' + error.message, 'error');
      }
    }

    // Select a zone and show its locations
    function selectZone(zoneId) {
      if (!zoneId) {
        document.getElementById('locationCards').innerHTML = `
          <div class="col-12 text-center text-muted py-5">
            <i class="fa-solid fa-warehouse fa-3x mb-3"></i>
            <p>Select a zone from the dropdown above to view locations</p>
          </div>
        `;
        return;
      }

      currentZoneId = zoneId;
      const zone = allZones.find(z => z.id == zoneId);
      
      if (!zone) return;

      document.getElementById('currentZoneName').textContent = `${zone.zone_code} - ${zone.zone_name}`;
      
      const locations = zone.locations || [];
      const cardsDiv = document.getElementById('locationCards');
      
      if (locations.length === 0) {
        cardsDiv.innerHTML = `
          <div class="col-12 text-center text-muted py-4">
            <i class="fa-solid fa-location-dot fa-2x mb-2"></i>
            <p>No locations in this zone. Click "Add Location" to create one.</p>
          </div>
        `;
        return;
      }

      // Render location cards horizontally (now showing aisles)
      cardsDiv.innerHTML = locations.map(loc => {
        const utilization = loc.capacity_units > 0 ? Math.round((loc.current_units / loc.capacity_units) * 100) : 0;
        const statusClass = utilization > 80 ? 'danger' : utilization > 50 ? 'warning' : 'success';
        
        // Use aisle_code if available, fallback to location_code for backward compatibility
        const locationCode = loc.aisle_code || loc.location_code || 'Unknown';
        const locationName = loc.aisle_name || loc.location_name || 'Storage Aisle';
        
        return `
          <div class="col-md-3">
            <div class="card h-100 border-${statusClass}">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                  <h6 class="mb-0">${locationCode}</h6>
                  <button class="btn btn-sm btn-outline-danger" onclick="deleteLocation(${loc.id})" title="Delete">
                    <i class="fa-solid fa-trash"></i>
                  </button>
                </div>
                <p class="text-muted small mb-2">${locationName}</p>
                <div class="progress mb-2" style="height: 8px;">
                  <div class="progress-bar bg-${statusClass}" style="width: ${utilization}%"></div>
                </div>
                <small class="text-muted">${loc.current_units || 0} / ${loc.capacity_units || 100} units (${utilization}%)</small>
                ${loc.bin_count ? `<br><small class="text-info">${loc.bin_count} bins</small>` : ''}
              </div>
            </div>
          </div>
        `;
      }).join('');
    }

    // Add location to current zone
    function addLocationToCurrentZone() {
      if (!currentZoneId) {
        Swal.fire('Error', 'Please select a zone first', 'warning');
        return;
      }
      addLocation(currentZoneId);
    }

    // Render warehouse map
    function renderWarehouseMap(zones) {
      const mapDiv = document.getElementById('warehouseMap');
      
      if (!zones || zones.length === 0) {
        mapDiv.innerHTML = `
          <div class="text-center p-5 text-white">
            <i class="fa-solid fa-warehouse" style="font-size: 3rem; opacity: 0.5;"></i>
            <p class="mt-3">No zones configured. Click "Add Zone" to get started.</p>
          </div>
        `;
        return;
      }

      let html = '<div class="row">';
      zones.forEach(zone => {
        const locations = zone.locations || [];
        // Use the zone's total values if available, otherwise calculate from locations
        const totalCapacity = zone.total_capacity_units || locations.reduce((sum, loc) => sum + (loc.capacity_units || 0), 0);
        const totalUsed = zone.total_current_units || locations.reduce((sum, loc) => sum + (loc.current_units || 0), 0);
        const utilization = totalCapacity > 0 ? Math.round((totalUsed / totalCapacity) * 100) : 0;
        
        html += `
          <div class="col-md-4 mb-3">
            <div class="zone-box ${zone.zone_type}">
              <h6 class="mb-2">${zone.zone_code} - ${zone.zone_name}</h6>
              <span class="badge bg-${zone.zone_type === 'storage' ? 'success' : zone.zone_type === 'receiving' ? 'primary' : 'warning'} mb-2">
                ${zone.zone_type}
              </span>
              <div class="small text-muted mb-2">${locations.length} locations</div>
              <div class="capacity-bar mb-2">
                <div class="capacity-fill ${utilization > 80 ? 'danger' : utilization > 60 ? 'warning' : ''}" style="width: ${utilization}%"></div>
              </div>
              <small>${utilization}% utilized</small>
            </div>
          </div>
        `;
      });
      html += '</div>';
      
      mapDiv.innerHTML = html;
    }

    // Add new zone
    async function addZone() {
      const { value: formValues } = await Swal.fire({
        title: 'Add New Zone',
        html: `
          <div class="mb-3 text-start">
            <label class="form-label">Zone Code</label>
            <input id="zone-code" class="swal2-input" placeholder="e.g., A, B, C">
          </div>
          <div class="mb-3 text-start">
            <label class="form-label">Zone Name</label>
            <input id="zone-name" class="swal2-input" placeholder="e.g., Electronics Storage">
          </div>
          <div class="mb-3 text-start">
            <label class="form-label">Zone Type</label>
            <select id="zone-type" class="swal2-select">
              <option value="storage">Storage</option>
              <option value="receiving">Receiving</option>
              <option value="shipping">Shipping</option>
            </select>
          </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Create Zone',
        preConfirm: () => {
          return {
            warehouse_id: 1,
            zone_code: document.getElementById('zone-code').value,
            zone_name: document.getElementById('zone-name').value,
            zone_type: document.getElementById('zone-type').value
          };
        }
      });

      if (formValues) {
        try {
          const response = await fetch('api/sws_locations.php?action=zone', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(formValues)
          });
          const data = await response.json();
          
          if (data.ok) {
            Swal.fire('Success!', 'Zone created successfully', 'success');
            loadLocationTree();
          } else {
            throw new Error(data.error);
          }
        } catch (error) {
          Swal.fire('Error', error.message, 'error');
        }
      }
    }

    // Add aisle to zone (updated for new warehouse structure)
    async function addLocation(zoneId) {
      const { value: formValues } = await Swal.fire({
        title: 'Add New Aisle',
        html: `
          <div class="mb-3 text-start">
            <label class="form-label">Aisle Code</label>
            <input id="aisle-code" class="swal2-input" placeholder="e.g., A04, A05">
          </div>
          <div class="mb-3 text-start">
            <label class="form-label">Aisle Name</label>
            <input id="aisle-name" class="swal2-input" placeholder="e.g., Electronics Aisle">
          </div>
          <div class="mb-3 text-start">
            <label class="form-label">Number of Racks</label>
            <input id="rack-count" type="number" class="swal2-input" value="2" min="1" max="10">
          </div>
          <div class="mb-3 text-start">
            <label class="form-label">Bins per Rack</label>
            <input id="bins-per-rack" type="number" class="swal2-input" value="12" min="1" max="50">
          </div>
          <div class="mb-3 text-start">
            <label class="form-label">Bin Capacity (units each)</label>
            <input id="bin-capacity" type="number" class="swal2-input" value="50" min="1">
          </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Create Aisle',
        width: '500px',
        preConfirm: () => {
          const aisleCode = document.getElementById('aisle-code').value;
          const aisleName = document.getElementById('aisle-name').value;
          const rackCount = parseInt(document.getElementById('rack-count').value);
          const binsPerRack = parseInt(document.getElementById('bins-per-rack').value);
          const binCapacity = parseInt(document.getElementById('bin-capacity').value);
          
          if (!aisleCode || !aisleName) {
            Swal.showValidationMessage('Aisle code and name are required');
            return false;
          }
          
          return {
            zone_id: zoneId,
            aisle_code: aisleCode.toUpperCase(),
            aisle_name: aisleName,
            rack_count: rackCount,
            bins_per_rack: binsPerRack,
            bin_capacity: binCapacity
          };
        }
      });

      if (formValues) {
        try {
          const response = await Api.send('api/sws_locations.php?action=create_aisle', 'POST', formValues);
          if (response.ok) {
            Swal.fire('Success!', `Aisle created with ${formValues.rack_count} racks and ${formValues.rack_count * formValues.bins_per_rack} bins`, 'success');
            loadLocationTree();
          } else {
            throw new Error(response.error || 'Failed to create aisle');
          }
        } catch (error) {
          Swal.fire('Error', error.message, 'error');
        }
      }
    }

    // Delete location
    async function deleteLocation(locationId) {
      const result = await Swal.fire({
        title: 'Delete Location?',
        text: 'This will deactivate the location',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete it'
      });

      if (result.isConfirmed) {
        try {
          const response = await fetch(`api/sws_locations.php?action=location&id=${locationId}`, {
            method: 'DELETE'
          });
          const data = await response.json();
          
          if (data.ok) {
            Swal.fire('Deleted!', 'Location has been deleted', 'success');
            loadLocationTree();
          } else {
            throw new Error(data.error);
          }
        } catch (error) {
          Swal.fire('Error', error.message, 'error');
        }
      }
    }

    // Delete zone
    async function deleteZone(zoneId) {
      const result = await Swal.fire({
        title: 'Delete Zone?',
        text: 'This will deactivate the zone and all its locations',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete it'
      });

      if (result.isConfirmed) {
        try {
          const response = await fetch(`api/sws_locations.php?action=zone&id=${zoneId}`, {
            method: 'DELETE'
          });
          const data = await response.json();
          
          if (data.ok) {
            Swal.fire('Deleted!', 'Zone has been deleted', 'success');
            loadLocationTree();
          } else {
            throw new Error(data.error);
          }
        } catch (error) {
          Swal.fire('Error', error.message, 'error');
        }
      }
    }

    function refreshMap() {
      loadLocationTree();
    }

    function toggleMapView() {
      const mapDiv = document.getElementById('warehouseMap');
      mapDiv.classList.toggle('fullscreen');
    }

    // Load location details
    function loadLocationDetails(type, id, items) {
      const detailsDiv = document.getElementById('locationDetails');
      const contentDiv = document.getElementById('locationDetailsContent');
      
      let html = '';
      
      switch (type) {
        case 'zone':
          html = `
            <h6>Zone Details</h6>
            <p><strong>Type:</strong> ${items[0]?.zone_type || 'N/A'}</p>
            <p><strong>Capacity:</strong> ${items[0]?.capacity_cubic_meters || 'N/A'} m³</p>
            <hr>
            <h6>Aisles (${items.length})</h6>
            <div class="list-group">
              ${items.map(aisle => `
                <div class="list-group-item d-flex justify-content-between align-items-center">
                  <div>
                    <strong>${aisle.aisle_code}</strong> - ${aisle.aisle_name || 'Unnamed'}
                    <small class="text-muted d-block">${aisle.rack_count || 0} racks</small>
                  </div>
                  <button class="btn btn-outline-primary btn-sm" onclick="selectLocation('aisle', ${aisle.id}, this)">
                    View
                  </button>
                </div>
              `).join('')}
            </div>
          `;
          break;
          
        case 'aisle':
          html = `
            <h6>Aisle Details</h6>
            <p><strong>Description:</strong> ${items[0]?.description || 'N/A'}</p>
            <hr>
            <h6>Racks (${items.length})</h6>
            <div class="list-group">
              ${items.map(rack => `
                <div class="list-group-item d-flex justify-content-between align-items-center">
                  <div>
                    <strong>${rack.rack_code}</strong> - ${rack.rack_name || 'Unnamed'}
                    <small class="text-muted d-block">${rack.levels || 0} levels • ${rack.bin_count || 0} bins</small>
                  </div>
                  <button class="btn btn-outline-primary btn-sm" onclick="selectLocation('rack', ${rack.id}, this)">
                    View
                  </button>
                </div>
              `).join('')}
            </div>
          `;
          break;
          
        case 'rack':
          html = `
            <h6>Rack Details</h6>
            <p><strong>Levels:</strong> ${items[0]?.levels || 'N/A'}</p>
            <p><strong>Dimensions:</strong> ${items[0]?.width_cm || 'N/A'} × ${items[0]?.depth_cm || 'N/A'} × ${items[0]?.height_cm || 'N/A'} cm</p>
            <p><strong>Capacity:</strong> ${items[0]?.capacity_weight_kg || 'N/A'} kg</p>
            <hr>
            <h6>Bins (${items.length})</h6>
            <div class="list-group">
              ${items.map(bin => `
                <div class="list-group-item d-flex justify-content-between align-items-center">
                  <div>
                    <strong>${bin.bin_code}</strong> - Level ${bin.level}, Position ${bin.position}
                    <small class="text-muted d-block">${bin.bin_type} • ${bin.capacity_units || 0} units capacity</small>
                    <div class="capacity-bar mt-1">
                      <div class="capacity-fill" style="width: ${(bin.current_units / bin.capacity_units * 100) || 0}%"></div>
                    </div>
                  </div>
                  <button class="btn btn-outline-primary btn-sm" onclick="selectLocation('bin', ${bin.id}, this)">
                    View
                  </button>
                </div>
              `).join('')}
            </div>
          `;
          break;
          
        case 'bin':
          html = `
            <h6>Bin Details</h6>
            <p><strong>Type:</strong> ${items[0]?.bin_type || 'N/A'}</p>
            <p><strong>Capacity:</strong> ${items[0]?.capacity_units || 'N/A'} units</p>
            <p><strong>Current:</strong> ${items[0]?.current_units || 0} units</p>
            <hr>
            <h6>Inventory (${items.length})</h6>
            <div class="list-group">
              ${items.map(item => `
                <div class="list-group-item">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <strong>${item.product_name}</strong> (${item.sku})
                      <small class="text-muted d-block">${item.quantity} units</small>
                      ${item.batch_number ? `<small class="text-info d-block">Batch: ${item.batch_number}</small>` : ''}
                      ${item.expiry_date ? `<small class="text-warning d-block">Expires: ${item.expiry_date}</small>` : ''}
                    </div>
                    <span class="badge bg-primary">${item.quantity}</span>
                  </div>
                </div>
              `).join('')}
            </div>
          `;
          break;
      }
      
      contentDiv.innerHTML = html;
      detailsDiv.style.display = 'block';
    }

    // OLD functions removed - using SweetAlert2 versions above

    // Load inventory
    async function loadInventory() {
      try {
        const warehouseId = document.getElementById('warehouseFilter')?.value || '';
        const url = warehouseId ? `api/sws_inventory.php?warehouse_id=${warehouseId}` : 'api/sws_inventory.php';
        const response = await Api.get(url);
        const inventory = response.data.items || [];
        
        const tbody = document.getElementById('inventoryTable');
        if (inventory.length === 0) {
          tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No inventory found</td></tr>';
          return;
        }
        
        tbody.innerHTML = inventory.map(item => `
          <tr>
            <td>${item.product_name}</td>
            <td><code>${item.sku}</code></td>
            <td>
              <small>${item.warehouse_name || 'Main Warehouse'}</small>
              ${item.locations_text ? `<br><small class="text-muted">${item.locations_text}</small>` : ''}
            </td>
            <td><span class="badge bg-primary">${item.quantity}</span></td>
            <td><span class="badge bg-warning">${item.reserved_quantity || 0}</span></td>
            <td><span class="badge bg-success">${item.available_quantity || item.quantity}</span></td>
            <td>${item.batch_number || '-'}</td>
            <td>${item.expiry_date || '-'}</td>
            <td>
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="transferInventory(${item.id})" title="Transfer">
                  <i class="fa-solid fa-arrows-rotate"></i>
                </button>
                <button class="btn btn-outline-info" onclick="adjustInventory(${item.id})" title="Adjust">
                  <i class="fa-solid fa-edit"></i>
                </button>
                ${item.barcode ? `
                  <button class="btn btn-outline-secondary" title="Show Barcode" onclick="showProductBarcode('${item.barcode}')">
                    <i class="fa-solid fa-barcode"></i>
                  </button>
                ` : ''}
              </div>
            </td>
          </tr>
        `).join('');
      } catch (error) {
        console.error('Error loading inventory:', error);
        document.getElementById('inventoryTable').innerHTML = '<tr><td colspan="9" class="text-center text-danger">Error loading inventory</td></tr>';
      }
    }

    // Load products
    async function loadProducts() {
      try {
        const response = await Api.get('api/products.php');
        const products = response.data.items || [];
        
        const tbody = document.getElementById('productsTable');
        if (products.length === 0) {
          tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No products found</td></tr>';
          return;
        }
        
        tbody.innerHTML = products.map(product => `
          <tr>
            <td><code>${product.sku}</code></td>
            <td>${product.name}</td>
            <td>${product.category_name || '-'}</td>
            <td>₱${parseFloat(product.unit_price || 0).toFixed(2)}</td>
            <td>
              <span class="badge ${product.total_inventory > 0 ? 'bg-success' : 'bg-warning'}">
                ${product.total_inventory || 0} units
              </span>
              ${product.warehouse_count > 1 ? `<small class="text-muted d-block">${product.warehouse_count} warehouses</small>` : ''}
            </td>
            <td>
              <span class="badge ${product.is_active ? 'bg-success' : 'bg-secondary'}">
                ${product.is_active ? 'Active' : 'Inactive'}
              </span>
            </td>
            <td>
              <div class="btn-group btn-group-sm" role="group">
                ${!product.barcode || product.barcode === 'null' ? `
                  <button type="button" class="btn btn-outline-warning" title="Generate Barcode" onclick="generateBarcode(${product.id})">
                    <i class="fa-solid fa-qrcode"></i>
                  </button>
                ` : `
                  <button type="button" class="btn btn-outline-secondary" title="Show Barcode" onclick="showProductBarcode('${product.barcode}')">
                    <i class="fa-solid fa-barcode"></i>
                  </button>
                `}
                <button type="button" class="btn btn-outline-success" title="Move to Inventory" onclick="moveToInventory(${product.id})">
                  <i class="fa-solid fa-warehouse"></i>
                </button>
                <button type="button" class="btn btn-outline-info" title="AI Analysis" onclick="aiAnalyzeProduct(${product.id})">
                  <i class="fa-solid fa-robot"></i>
                </button>
                <button type="button" class="btn btn-outline-primary" title="Edit Product" onclick="editProduct(${product.id})">
                  <i class="fa-solid fa-edit"></i>
                </button>
                <button type="button" class="btn btn-outline-danger" title="Delete Product" onclick="deleteProduct(${product.id})">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </div>
            </td>
          </tr>
        `).join('');
      } catch (error) {
        console.error('Error loading products:', error);
        document.getElementById('productsTable').innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error loading products</td></tr>';
      }
    }

    // Load movements
    async function loadMovements() {
      try {
        const warehouseId = document.getElementById('warehouseFilter')?.value || '';
        const url = warehouseId ? `api/sws_movements.php?action=list&warehouse_id=${warehouseId}` : 'api/sws_movements.php?action=list';
        const response = await Api.get(url);
        const movements = response.data.items || [];
        
        const tbody = document.getElementById('movementsTable');
        if (movements.length === 0) {
          tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No movements found</td></tr>';
          return;
        }
        
        tbody.innerHTML = movements.map(movement => `
          <tr>
            <td>${new Date(movement.created_at).toLocaleDateString()}</td>
            <td>${movement.product_name || '-'}</td>
            <td><span class="badge bg-info">${movement.transaction_type}</span></td>
            <td>${movement.from_location || '-'}</td>
            <td>${movement.to_location || '-'}</td>
            <td><span class="badge ${movement.quantity > 0 ? 'bg-success' : 'bg-danger'}">${movement.quantity > 0 ? '+' : ''}${movement.quantity}</span></td>
            <td>${movement.reason_code || '-'}</td>
            <td>${movement.performed_by_name || '-'}</td>
          </tr>
        `).join('');
      } catch (error) {
        console.error('Error loading movements:', error);
        document.getElementById('movementsTable').innerHTML = '<tr><td colspan="8" class="text-center text-danger">Error loading movements</td></tr>';
      }
    }

    // Load warehouses for filter
    async function loadWarehouses() {
      try {
        const response = await Api.get('api/warehouses.php');
        const warehouses = response.data.items || [];
        
        const select = document.getElementById('warehouseFilter');
        select.innerHTML = '<option value="">All Warehouses</option>' +
          warehouses.map(warehouse => `<option value="${warehouse.id}">${warehouse.name}</option>`).join('');
      } catch (error) {
        console.error('Error loading warehouses:', error);
      }
    }

    // Utility functions
    function showAlert(message, type = 'info') {
      const alertDiv = document.createElement('div');
      alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
      alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      `;
      
      document.querySelector('.card-body').insertBefore(alertDiv, document.querySelector('.card-body').firstChild);
      
      // Auto-dismiss after 5 seconds
      setTimeout(() => {
        if (alertDiv.parentNode) {
          alertDiv.remove();
        }
      }, 5000);
    }

    function refreshMap() {
      if (selectedLocation) {
        selectLocation(selectedLocation.type, selectedLocation.id);
      }
    }

    function toggleMapView() {
      // Implement full-screen map view
      console.log('Toggle map view');
    }

    // Placeholder functions for future implementation
    function editLocation(type, id) {
      console.log('Edit location:', type, id);
    }


    // Barcode UI handlers
    async function showProductBarcode(barcode) {
      if (!barcode) return showAlert('No barcode available for this product', 'warning');
      const svg = await SWS.generateBarcode(barcode, 'svg');
      const modal = document.createElement('div');
      modal.className = 'modal fade';
      modal.innerHTML = `
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Barcode: ${barcode}</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">${svg}</div>
          </div>
        </div>`;
      document.body.appendChild(modal);
      const bsModal = new bootstrap.Modal(modal);
      bsModal.show();
      modal.addEventListener('hidden.bs.modal', () => modal.remove());
    }

    async function printProductBarcode(barcode) {
      if (!barcode) return showAlert('No barcode available for this product', 'warning');
      await SWS.printBarcode(barcode);
    }

    async function scanProductBarcode(barcode) {
      if (!barcode) return showAlert('No barcode to scan', 'warning');
      const product = await SWS.scanBarcode(barcode);
      if (product) {
        showAlert('Product found: ' + product.name, 'success');
      }
    }

    function addProduct() {
      Swal.fire({
        title: 'Add New Product',
        html: `
          <div class="mb-3">
            <label class="form-label">Product Name</label>
            <input id="swal-name" class="swal2-input" placeholder="Product Name" required>
          </div>
          <div class="mb-3">
            <label class="form-label">SKU</label>
            <input id="swal-sku" class="swal2-input" placeholder="SKU" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Unit Price</label>
            <input id="swal-price" class="swal2-input" type="number" step="0.01" placeholder="0.00" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea id="swal-description" class="swal2-textarea" placeholder="Product description"></textarea>
          </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Create Product',
        preConfirm: () => {
          const name = document.getElementById('swal-name').value;
          const sku = document.getElementById('swal-sku').value;
          const price = document.getElementById('swal-price').value;
          
          if (!name || !sku || !price) {
            Swal.showValidationMessage('Please fill in all required fields');
            return false;
          }
          
          return {
            name: name,
            sku: sku,
            unit_price: parseFloat(price),
            description: document.getElementById('swal-description').value
          };
        }
      }).then(async (result) => {
        if (result.isConfirmed) {
          try {
            const response = await Api.send('api/products.php', 'POST', result.value);
            if (response.ok) {
              Swal.fire('Success!', 'Product created successfully', 'success');
              loadProducts();
            } else {
              throw new Error(response.error || 'Failed to create product');
            }
          } catch (error) {
            Swal.fire('Error', error.message, 'error');
          }
        }
      });
    }

    async function editProduct(id) {
      try {
        // Get product details first
        const response = await Api.get(`api/products.php?id=${id}`);
        if (!response.ok) throw new Error('Product not found');
        
        const product = response.data;
        
        const { value: formValues } = await Swal.fire({
          title: 'Edit Product',
          html: `
            <div class="mb-3">
              <label class="form-label">Product Name</label>
              <input id="swal-name" class="swal2-input" value="${product.name}" required>
            </div>
            <div class="mb-3">
              <label class="form-label">SKU</label>
              <input id="swal-sku" class="swal2-input" value="${product.sku}" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Unit Price</label>
              <input id="swal-price" class="swal2-input" type="number" step="0.01" value="${product.unit_price}" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Description</label>
              <textarea id="swal-description" class="swal2-textarea">${product.description || ''}</textarea>
            </div>
          `,
          focusConfirm: false,
          showCancelButton: true,
          confirmButtonText: 'Update Product',
          preConfirm: () => {
            return {
              name: document.getElementById('swal-name').value,
              sku: document.getElementById('swal-sku').value,
              unit_price: parseFloat(document.getElementById('swal-price').value),
              description: document.getElementById('swal-description').value
            };
          }
        });

        if (formValues) {
          const updateResponse = await Api.send(`api/products.php?id=${id}`, 'PUT', formValues);
          if (updateResponse.ok) {
            Swal.fire('Success!', 'Product updated successfully', 'success');
            loadProducts();
          } else {
            throw new Error(updateResponse.error || 'Failed to update product');
          }
        }
      } catch (error) {
        Swal.fire('Error', error.message, 'error');
      }
    }

    async function deleteProduct(id) {
      const result = await Swal.fire({
        title: 'Delete Product?',
        text: 'This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete it!'
      });

      if (result.isConfirmed) {
        try {
          const response = await Api.send(`api/products.php?id=${id}`, 'DELETE');
          if (response.ok) {
            Swal.fire('Deleted!', 'Product has been deleted', 'success');
            loadProducts();
          } else {
            throw new Error(response.error || 'Failed to delete product');
          }
        } catch (error) {
          Swal.fire('Error', error.message, 'error');
        }
      }
    }

    function addMovement() {
      console.log('Add movement');
    }

    async function transferInventory(inventoryId) {
      try {
        // Get available bins first
        const binsResponse = await Api.get('api/transfer_stock.php?action=bins');
        const bins = binsResponse.data.bins || [];
        
        const binOptions = bins.map(bin => 
          `<option value="${bin.id}">${bin.location_code} - ${bin.bin_name} (${bin.available_units}/${bin.capacity_units} available)</option>`
        ).join('');
        
        const { value: formValues } = await Swal.fire({
          title: 'Transfer Stock',
          html: `
            <div class="mb-3 text-start">
              <label class="form-label">From Bin</label>
              <select id="swal-from-bin" class="swal2-select" required>
                <option value="">-- Select Source Bin --</option>
                ${binOptions}
              </select>
            </div>
            <div class="mb-3 text-start">
              <label class="form-label">To Bin</label>
              <select id="swal-to-bin" class="swal2-select" required>
                <option value="">-- Select Destination Bin --</option>
                ${binOptions}
              </select>
            </div>
            <div class="mb-3 text-start">
              <label class="form-label">Quantity</label>
              <input id="swal-quantity" class="swal2-input" type="number" placeholder="Quantity" min="1" required>
            </div>
            <div class="mb-3 text-start">
              <label class="form-label">Reason</label>
              <select id="swal-reason" class="swal2-select">
                <option value="relocation">Relocation</option>
                <option value="optimization">Optimization</option>
                <option value="consolidation">Consolidation</option>
                <option value="maintenance">Maintenance</option>
              </select>
            </div>
            <div class="mb-3 text-start">
              <label class="form-label">Notes</label>
              <textarea id="swal-notes" class="swal2-textarea" placeholder="Optional notes"></textarea>
            </div>
          `,
          focusConfirm: false,
          showCancelButton: true,
          confirmButtonText: 'Transfer Stock',
          width: '600px',
          preConfirm: () => {
            const fromBin = document.getElementById('swal-from-bin').value;
            const toBin = document.getElementById('swal-to-bin').value;
            const quantity = document.getElementById('swal-quantity').value;
            
            if (!fromBin || !toBin || !quantity) {
              Swal.showValidationMessage('Please fill in all required fields');
              return false;
            }
            
            if (fromBin === toBin) {
              Swal.showValidationMessage('Source and destination bins must be different');
              return false;
            }
            
            return {
              from_bin_id: parseInt(fromBin),
              to_bin_id: parseInt(toBin),
              quantity: parseInt(quantity),
              reason_code: document.getElementById('swal-reason').value,
              notes: document.getElementById('swal-notes').value
            };
          }
        });

        if (formValues) {
          const response = await Api.send('api/transfer_stock.php', 'POST', formValues);

          if (response.ok) {
            Swal.fire('Success!', 'Stock transferred successfully', 'success');
            loadInventory();
            loadMovements();
          } else {
            throw new Error(response.error || 'Transfer failed');
          }
        }
      } catch (error) {
        Swal.fire('Error', error.message, 'error');
      }
    }

    async function adjustInventory(inventoryId) {
      const { value: formValues } = await Swal.fire({
        title: 'Adjust Inventory',
        html: `
          <div class="mb-3">
            <label class="form-label">Adjustment (+ or -)</label>
            <input id="swal-adjustment" class="swal2-input" type="number" placeholder="e.g., +10 or -5" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Reason</label>
            <select id="swal-reason" class="swal2-select">
              <option value="count_adjustment">Count Adjustment</option>
              <option value="damage">Damage</option>
              <option value="loss">Loss</option>
              <option value="found">Found</option>
              <option value="correction">Correction</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Notes (Required)</label>
            <textarea id="swal-notes" class="swal2-textarea" placeholder="Explain the adjustment" required></textarea>
          </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        preConfirm: () => {
          const notes = document.getElementById('swal-notes').value;
          if (!notes) {
            Swal.showValidationMessage('Notes are required for adjustments');
            return false;
          }
          return {
            adjustment: parseInt(document.getElementById('swal-adjustment').value),
            reason_code: document.getElementById('swal-reason').value,
            notes: notes
          };
        }
      });

      if (formValues) {
        try {
          const response = await Api.send('api/sws_inventory.php', 'PUT', {
            product_id: inventoryId,
            warehouse_id: 1,
            ...formValues
          });

          if (response.ok) {
            Swal.fire('Success!', 'Inventory adjusted successfully', 'success');
            loadInventory();
            loadMovements();
          } else {
            throw new Error(response.error || 'Adjustment failed');
          }
        } catch (error) {
          Swal.fire('Error', error.message, 'error');
        }
      }
    }

    // AI Demo functions
    async function showAILocationDemo() {
      const { value: productId } = await Swal.fire({
        title: 'AI Location Assignment Demo',
        input: 'number',
        inputLabel: 'Enter Product ID',
        inputPlaceholder: 'Product ID',
        showCancelButton: true
      });
      
      if (productId) {
        await SWS.autoAssignLocation(parseInt(productId), 10);
      }
    }

    async function showAIDemandDemo() {
      const { value: productId } = await Swal.fire({
        title: 'AI Demand Forecast Demo',
        input: 'number',
        inputLabel: 'Enter Product ID',
        inputPlaceholder: 'Product ID',
        showCancelButton: true
      });
      
      if (productId) {
        await SWS.predictDemand(parseInt(productId), 30);
      }
    }

    // Barcode Scanner Modal
    async function openBarcodeScanner() {
      const { value: barcode } = await Swal.fire({
        title: '<i class="fa-solid fa-barcode me-2"></i>Barcode Scanner',
        html: `
          <div class="text-center">
            <p class="mb-3">Enter or scan a barcode:</p>
            <input id="barcode-input" class="swal2-input" placeholder="Enter barcode..." autofocus>
            <div class="mt-3">
              <button class="btn btn-primary" onclick="startCameraScanner()">
                <i class="fa-solid fa-camera me-1"></i>Use Camera
              </button>
            </div>
            <div id="scanner-result" class="mt-3"></div>
          </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Search',
        preConfirm: () => {
          const input = document.getElementById('barcode-input').value;
          if (!input) {
            Swal.showValidationMessage('Please enter a barcode');
            return false;
          }
          return input;
        }
      });

      if (barcode) {
        searchByBarcode(barcode);
      }
    }

    async function searchByBarcode(barcode) {
      try {
        const response = await Api.get(`api/products.php?barcode=${barcode}`);
        
        if (response.ok && response.data) {
          const product = response.data;
          Swal.fire({
            title: 'Product Found!',
            html: `
              <div class="text-start">
                <p><strong>Name:</strong> ${product.name}</p>
                <p><strong>SKU:</strong> ${product.sku}</p>
                <p><strong>Barcode:</strong> ${product.barcode}</p>
                <p><strong>Stock:</strong> ${product.total_stock || 0} units</p>
              </div>
            `,
            icon: 'success'
          });
        } else {
          Swal.fire('Not Found', 'No product found with this barcode', 'warning');
        }
      } catch (error) {
        Swal.fire('Error', error.message, 'error');
      }
    }

    function startCameraScanner() {
      Swal.fire({
        title: 'Camera Scanner',
        html: `
          <div class="alert alert-info">
            <i class="fa-solid fa-info-circle me-2"></i>
            Camera scanning requires HTTPS or localhost.<br>
            For full camera support, open: <a href="barcode_scanner.html" target="_blank">barcode_scanner.html</a>
          </div>
        `,
        icon: 'info'
      });
    }

    // Check AI service health on AI Tools tab
    document.addEventListener('DOMContentLoaded', function() {
      const aiTab = document.getElementById('ai-tools-tab');
      if (aiTab) {
        aiTab.addEventListener('shown.bs.tab', async function () {
          const statusDiv = document.getElementById('aiHealthStatus');
          try {
            const response = await fetch('api/ai_warehouse.php?action=health');
            const data = await response.json();
            
            if (data.ok && data.data.ai_configured) {
              statusDiv.innerHTML = `
                <div class="alert alert-success mb-0">
                  <i class="fa-solid fa-check-circle me-2"></i>
                  <strong>AI Service: Active</strong><br>
                  <small>Provider: ${data.data.provider}</small>
                </div>
              `;
            } else {
              statusDiv.innerHTML = `
                <div class="alert alert-warning mb-0">
                  <i class="fa-solid fa-exclamation-triangle me-2"></i>
                  <strong>AI Service: Not Configured</strong><br>
                  <small>Please configure GEMINI_API_KEY in .env file</small>
                </div>
              `;
            }
          } catch (error) {
            statusDiv.innerHTML = `
              <div class="alert alert-danger mb-0">
                <i class="fa-solid fa-times-circle me-2"></i>
                <strong>AI Service: Error</strong><br>
                <small>${error.message}</small>
              </div>
            `;
          }
        });
      }
    });

    // New product action functions
    async function generateBarcode(productId) {
      try {
        const response = await Api.send('api/product_actions.php', 'POST', {
          action: 'generate_barcode',
          product_id: productId
        });

        if (response.ok) {
          Swal.fire({
            title: 'Barcode Generated!',
            html: `
              <p>Barcode: <strong>${response.data.barcode}</strong></p>
              ${response.data.barcode_image ? `<img src="data:image/png;base64,${response.data.barcode_image}" alt="Barcode" class="img-fluid">` : ''}
            `,
            icon: 'success'
          });
          loadProducts(); // Refresh the products list
        } else {
          throw new Error(response.error || 'Failed to generate barcode');
        }
      } catch (error) {
        Swal.fire('Error', error.message, 'error');
      }
    }

    async function moveToInventory(productId) {
      const { value: formValues } = await Swal.fire({
        title: 'Move Product to Inventory',
        html: `
          <div class="mb-3">
            <label class="form-label">Quantity</label>
            <input id="swal-quantity" class="swal2-input" type="number" placeholder="Quantity" min="1" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Warehouse</label>
            <select id="swal-warehouse" class="swal2-select">
              <option value="1">Main Warehouse</option>
            </select>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="swal-use-ai" checked>
            <label class="form-check-label" for="swal-use-ai">
              Use AI to suggest optimal location
            </label>
          </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        preConfirm: () => {
          const quantity = parseInt(document.getElementById('swal-quantity').value);
          if (!quantity || quantity <= 0) {
            Swal.showValidationMessage('Please enter a valid quantity');
            return false;
          }
          return {
            quantity: quantity,
            warehouse_id: parseInt(document.getElementById('swal-warehouse').value),
            use_ai: document.getElementById('swal-use-ai').checked
          };
        }
      });

      if (formValues) {
        try {
          const response = await Api.send('api/product_actions.php', 'POST', {
            action: 'move_to_inventory',
            product_id: productId,
            ...formValues
          });

          if (response.ok) {
            Swal.fire({
              title: 'Success!',
              html: `
                <p>Product moved to inventory successfully!</p>
                <p><strong>Quantity added:</strong> ${response.data.quantity_added}</p>
                ${response.data.ai_location ? `<p><strong>AI Location:</strong> ${response.data.ai_location}</p>` : ''}
              `,
              icon: 'success'
            });
            loadProducts();
            loadInventory();
          } else {
            throw new Error(response.error || 'Failed to move to inventory');
          }
        } catch (error) {
          Swal.fire('Error', error.message, 'error');
        }
      }
    }

    async function aiAnalyzeProduct(productId) {
      try {
        // Show loading
        Swal.fire({
          title: 'AI Analysis in Progress...',
          html: 'Analyzing product with AI...',
          allowOutsideClick: false,
          didOpen: () => {
            Swal.showLoading();
          }
        });

        const response = await Api.send('api/product_actions.php', 'POST', {
          action: 'ai_analyze_product',
          product_id: productId
        });

        if (response.ok) {
          const product = response.data.product;
          const analysis = response.data.ai_analysis;
          
          let analysisHtml = `
            <div class="text-start">
              <h6>Product: ${product.name}</h6>
              <p><strong>SKU:</strong> ${product.sku}</p>
              <p><strong>Current Inventory:</strong> ${product.total_inventory} units</p>
              <p><strong>Warehouses:</strong> ${product.warehouse_count}</p>
              <hr>
          `;

          if (analysis.demand_forecast) {
            analysisHtml += `
              <h6>📈 Demand Forecast (30 days)</h6>
              <p><strong>Predicted Demand:</strong> ${analysis.demand_forecast.predicted_demand} units</p>
              <p><strong>Confidence:</strong> ${(analysis.demand_forecast.confidence * 100).toFixed(1)}%</p>
              <p><strong>Days of Stock:</strong> ${analysis.days_of_stock} days</p>
              <p><strong>Reorder Recommended:</strong> ${analysis.demand_forecast.reorder_recommended ? 'Yes' : 'No'}</p>
              ${analysis.demand_forecast.reorder_quantity ? `<p><strong>Suggested Reorder:</strong> ${analysis.demand_forecast.reorder_quantity} units</p>` : ''}
              <hr>
            `;
          }

          if (analysis.barcode_context) {
            analysisHtml += `
              <h6>🏷️ Barcode Analysis</h6>
              <p><strong>Status:</strong> <span class="badge bg-${analysis.barcode_context.status === 'ok' ? 'success' : analysis.barcode_context.status === 'warning' ? 'warning' : 'danger'}">${analysis.barcode_context.status.toUpperCase()}</span></p>
              <p><strong>Message:</strong> ${analysis.barcode_context.message}</p>
              ${analysis.barcode_context.suggestions.length > 0 ? `
                <p><strong>Suggestions:</strong></p>
                <ul>
                  ${analysis.barcode_context.suggestions.map(s => `<li>${s}</li>`).join('')}
                </ul>
              ` : ''}
            `;
          }

          analysisHtml += '</div>';

          Swal.fire({
            title: '🤖 AI Analysis Results',
            html: analysisHtml,
            icon: 'info',
            width: '600px'
          });
        } else {
          throw new Error(response.error || 'AI analysis failed');
        }
      } catch (error) {
        Swal.fire('Error', error.message, 'error');
      }
    }

    // Warehouse Management Functions
    async function loadWarehousesList() {
      try {
        const response = await Api.get('api/warehouse_management.php');
        const warehouses = response.data.items || [];
        
        const tbody = document.getElementById('warehousesTable');
        if (warehouses.length === 0) {
          tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No warehouses found</td></tr>';
          return;
        }
        
        tbody.innerHTML = warehouses.map(warehouse => `
          <tr>
            <td><code>${warehouse.code}</code></td>
            <td>${warehouse.name}</td>
            <td>
              ${warehouse.city || '-'}
              ${warehouse.address ? `<br><small class="text-muted">${warehouse.address}</small>` : ''}
            </td>
            <td>${warehouse.manager_name || '-'}</td>
            <td><span class="badge bg-info">${warehouse.zone_count || 0} zones</span></td>
            <td><span class="badge bg-primary">${warehouse.product_count || 0} products</span></td>
            <td><span class="badge bg-success">${warehouse.total_inventory || 0} units</span></td>
            <td>
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="editWarehouse(${warehouse.id})" title="Edit">
                  <i class="fa-solid fa-edit"></i>
                </button>
                <button class="btn btn-outline-info" onclick="viewWarehouseDetails(${warehouse.id})" title="View Details">
                  <i class="fa-solid fa-eye"></i>
                </button>
                <button class="btn btn-outline-danger" onclick="deleteWarehouse(${warehouse.id})" title="Delete">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </div>
            </td>
          </tr>
        `).join('');
      } catch (error) {
        console.error('Error loading warehouses:', error);
        document.getElementById('warehousesTable').innerHTML = '<tr><td colspan="8" class="text-center text-danger">Error loading warehouses</td></tr>';
      }
    }

    async function addWarehouse() {
      const { value: formValues } = await Swal.fire({
        title: 'Add New Warehouse',
        html: `
          <div class="row">
            <div class="col-md-6">
              <div class="mb-3">
                <label class="form-label">Warehouse Code *</label>
                <input id="swal-code" class="swal2-input" placeholder="e.g., WH001" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Warehouse Name *</label>
                <input id="swal-name" class="swal2-input" placeholder="Main Warehouse" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Manager Name</label>
                <input id="swal-manager" class="swal2-input" placeholder="Manager Name">
              </div>
              <div class="mb-3">
                <label class="form-label">Phone</label>
                <input id="swal-phone" class="swal2-input" placeholder="Phone Number">
              </div>
            </div>
            <div class="col-md-6">
              <div class="mb-3">
                <label class="form-label">Address</label>
                <textarea id="swal-address" class="swal2-textarea" placeholder="Street Address"></textarea>
              </div>
              <div class="mb-3">
                <label class="form-label">City</label>
                <input id="swal-city" class="swal2-input" placeholder="City">
              </div>
              <div class="mb-3">
                <label class="form-label">Email</label>
                <input id="swal-email" class="swal2-input" type="email" placeholder="warehouse@company.com">
              </div>
            </div>
          </div>
          <div class="form-check mt-3">
            <input class="form-check-input" type="checkbox" id="swal-default-zones" checked>
            <label class="form-check-label" for="swal-default-zones">
              Create default zones (Receiving, Storage, Packing, Shipping)
            </label>
          </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Create Warehouse',
        width: '800px',
        preConfirm: () => {
          const code = document.getElementById('swal-code').value;
          const name = document.getElementById('swal-name').value;
          
          if (!code || !name) {
            Swal.showValidationMessage('Code and Name are required');
            return false;
          }
          
          return {
            code: code.toUpperCase(),
            name: name,
            manager_name: document.getElementById('swal-manager').value,
            phone: document.getElementById('swal-phone').value,
            address: document.getElementById('swal-address').value,
            city: document.getElementById('swal-city').value,
            email: document.getElementById('swal-email').value,
            create_default_zones: document.getElementById('swal-default-zones').checked
          };
        }
      });

      if (formValues) {
        try {
          const response = await Api.send('api/warehouse_management.php', 'POST', formValues);
          if (response.ok) {
            Swal.fire('Success!', 'Warehouse created successfully', 'success');
            loadWarehousesList();
            loadWarehouses(); // Refresh warehouse filter dropdown
          } else {
            throw new Error(response.error || 'Failed to create warehouse');
          }
        } catch (error) {
          Swal.fire('Error', error.message, 'error');
        }
      }
    }

    async function editWarehouse(id) {
      try {
        // Get warehouse details first
        const response = await Api.get(`api/warehouses.php?id=${id}`);
        if (!response.ok) throw new Error('Warehouse not found');
        
        const warehouse = response.data;
        
        const { value: formValues } = await Swal.fire({
          title: 'Edit Warehouse',
          html: `
            <div class="row">
              <div class="col-md-6">
                <div class="mb-3">
                  <label class="form-label">Warehouse Code</label>
                  <input id="swal-code" class="swal2-input" value="${warehouse.code}" required>
                </div>
                <div class="mb-3">
                  <label class="form-label">Warehouse Name</label>
                  <input id="swal-name" class="swal2-input" value="${warehouse.name}" required>
                </div>
                <div class="mb-3">
                  <label class="form-label">Manager Name</label>
                  <input id="swal-manager" class="swal2-input" value="${warehouse.manager_name || ''}">
                </div>
                <div class="mb-3">
                  <label class="form-label">Phone</label>
                  <input id="swal-phone" class="swal2-input" value="${warehouse.phone || ''}">
                </div>
              </div>
              <div class="col-md-6">
                <div class="mb-3">
                  <label class="form-label">Address</label>
                  <textarea id="swal-address" class="swal2-textarea">${warehouse.address || ''}</textarea>
                </div>
                <div class="mb-3">
                  <label class="form-label">City</label>
                  <input id="swal-city" class="swal2-input" value="${warehouse.city || ''}">
                </div>
                <div class="mb-3">
                  <label class="form-label">Email</label>
                  <input id="swal-email" class="swal2-input" value="${warehouse.email || ''}">
                </div>
              </div>
            </div>
          `,
          focusConfirm: false,
          showCancelButton: true,
          confirmButtonText: 'Update Warehouse',
          width: '800px',
          preConfirm: () => {
            return {
              code: document.getElementById('swal-code').value.toUpperCase(),
              name: document.getElementById('swal-name').value,
              manager_name: document.getElementById('swal-manager').value,
              phone: document.getElementById('swal-phone').value,
              address: document.getElementById('swal-address').value,
              city: document.getElementById('swal-city').value,
              email: document.getElementById('swal-email').value
            };
          }
        });

        if (formValues) {
          const updateResponse = await Api.send(`api/warehouse_management.php?id=${id}`, 'PUT', formValues);
          if (updateResponse.ok) {
            Swal.fire('Success!', 'Warehouse updated successfully', 'success');
            loadWarehousesList();
          } else {
            throw new Error(updateResponse.error || 'Failed to update warehouse');
          }
        }
      } catch (error) {
        Swal.fire('Error', error.message, 'error');
      }
    }

    async function deleteWarehouse(id) {
      const result = await Swal.fire({
        title: 'Delete Warehouse?',
        text: 'This action cannot be undone! Make sure the warehouse has no inventory.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete it!'
      });

      if (result.isConfirmed) {
        try {
          const response = await Api.send(`api/warehouse_management.php?id=${id}`, 'DELETE');
          if (response.ok) {
            Swal.fire('Deleted!', 'Warehouse has been deleted', 'success');
            loadWarehousesList();
          } else {
            throw new Error(response.error || 'Failed to delete warehouse');
          }
        } catch (error) {
          Swal.fire('Error', error.message, 'error');
        }
      }
    }

    async function viewWarehouseDetails(id) {
      try {
        const response = await Api.get(`api/warehouses.php?id=${id}`);
        if (!response.ok) throw new Error('Warehouse not found');
        
        const warehouse = response.data;
        
        Swal.fire({
          title: `${warehouse.name} (${warehouse.code})`,
          html: `
            <div class="text-start">
              <h6>Contact Information</h6>
              <p><strong>Manager:</strong> ${warehouse.manager_name || 'Not assigned'}</p>
              <p><strong>Phone:</strong> ${warehouse.phone || 'Not provided'}</p>
              <p><strong>Email:</strong> ${warehouse.email || 'Not provided'}</p>
              
              <h6>Address</h6>
              <p>${warehouse.address || 'No address provided'}</p>
              <p>${warehouse.city || 'No city provided'}</p>
              
              <h6>Statistics</h6>
              <p><strong>Zones:</strong> ${warehouse.zone_count || 0}</p>
              <p><strong>Products:</strong> ${warehouse.product_count || 0}</p>
              <p><strong>Total Inventory:</strong> ${warehouse.total_inventory || 0} units</p>
              <p><strong>Created:</strong> ${new Date(warehouse.created_at).toLocaleDateString()}</p>
            </div>
          `,
          icon: 'info',
          width: '500px'
        });
      } catch (error) {
        Swal.fire('Error', error.message, 'error');
      }
    }
  </script>
</body>
</html>
