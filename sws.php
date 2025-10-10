<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>DropIT · SWS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <style>
    #scanner-container { position: relative; max-width: 400px; margin: 0 auto; }
    #scanner-video { width: 100%; height: auto; border-radius: 8px; }
    #scanner-overlay { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); border: 2px solid #28a745; width: 200px; height: 100px; border-radius: 8px; }
    .scanner-result { background: #d4edda; border: 1px solid #c3e6cb; padding: 10px; border-radius: 4px; margin-top: 10px; }
    .nav-pills .nav-link.active { background-color: #0d6efd; }
  </style>
</head>
<body>
  <main class="container-fluid py-3">
    <div class="row g-3">
      <aside class="col-12 col-md-3 col-lg-2">
        <div id="sidebar" class="card shadow-sm p-2"></div>
      </aside>
      <section class="col-12 col-md-9 col-lg-10">
        <div class="card shadow-sm">
          <div class="card-body">
            <h5 class="card-title mb-5"><i class="fa-solid fa-warehouse me-2 text-primary"></i>SWS – Smart Warehousing System</h5>
            
            <!-- Navigation Tabs -->
            <ul class="nav nav-pills mb-4" id="swsTabs" role="tablist" style="margin-top: 2rem;">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="scanner-tab" data-bs-toggle="pill" data-bs-target="#scanner" type="button" role="tab">
                  <i class="fa-solid fa-qrcode me-1"></i>Barcode Scanner
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
                <button class="nav-link" id="warehouses-tab" data-bs-toggle="pill" data-bs-target="#warehouses" type="button" role="tab">
                  <i class="fa-solid fa-warehouse me-1"></i>Warehouses
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="archive-tab" data-bs-toggle="pill" data-bs-target="#archive" type="button" role="tab">
                  <i class="fa-solid fa-archive me-1"></i>Archive
                </button>
              </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content" id="swsTabContent">
              <!-- Barcode Scanner Tab -->
              <div class="tab-pane fade show active" id="scanner" role="tabpanel">
                <div class="row g-3">
                  <div class="col-12 col-lg-6">
                    <div id="scanner-container">
                      <video id="scanner-video" autoplay muted playsinline style="display:none;"></video>
                      <div id="scanner-overlay" style="display:none;"></div>
                      <div id="scanner-placeholder" class="text-center p-4 border rounded">
                        <i class="fa-solid fa-camera fa-3x text-muted mb-3"></i>
                        <p class="text-muted">Click "Start Scanner" to begin barcode scanning</p>
                        <button id="start-scanner" class="btn btn-primary">
                          <i class="fa-solid fa-play me-1"></i>Start Scanner
                        </button>
                      </div>
                    </div>
                    <div class="mt-3 text-center">
                      <button id="stop-scanner" class="btn btn-secondary me-2" style="display:none;">
                        <i class="fa-solid fa-stop me-1"></i>Stop Scanner
                      </button>
                      <button id="toggle-camera" class="btn btn-outline-secondary" style="display:none;">
                        <i class="fa-solid fa-camera-rotate me-1"></i>Switch Camera
                      </button>
                    </div>
                  </div>
                  <div class="col-12 col-lg-6">
                    <div id="scan-results">
                      <h6>Scan Results</h6>
                      <div id="scan-output" class="text-muted">No scans yet</div>
                    </div>
                    
                    <!-- Quick Add Product Form -->
                    <div id="quick-add-form" style="display:none;" class="mt-4">
                      <h6>Quick Add Product</h6>
                      <form id="add-product-form">
                        <input type="hidden" id="scanned-barcode">
                        <div class="mb-3">
                          <label class="form-label">Product Name *</label>
                          <input type="text" class="form-control" id="product-name" required>
                        </div>
                        <div class="mb-3">
                          <label class="form-label">Unit Price *</label>
                          <input type="number" step="0.01" class="form-control" id="product-price" required>
                        </div>
                        <div class="mb-3">
                          <label class="form-label">Description</label>
                          <textarea class="form-control" id="product-description" rows="2"></textarea>
                        </div>
                        <div class="row">
                          <div class="col-6">
                            <label class="form-label">Warehouse</label>
                            <select class="form-select" id="product-warehouse">
                              <option value="">Select warehouse</option>
                            </select>
                          </div>
                          <div class="col-6">
                            <label class="form-label">Initial Quantity</label>
                            <input type="number" class="form-control" id="product-quantity" min="0" value="1">
                          </div>
                        </div>
                        <div class="mt-3">
                          <button type="submit" class="btn btn-success me-2">
                            <i class="fa-solid fa-plus me-1"></i>Add Product
                          </button>
                          <button type="button" id="cancel-add" class="btn btn-secondary">Cancel</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Inventory Tab -->
              <div class="tab-pane fade" id="inventory" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6 class="mb-0">Inventory Management</h6>
                  <button class="btn btn-primary btn-sm" id="adjust-inventory-btn">
                    <i class="fa-solid fa-plus-minus me-1"></i>Adjust Inventory
                  </button>
                </div>
                <div id="inventory-list">Loading...</div>
              </div>

              <!-- Products Tab -->
              <div class="tab-pane fade" id="products" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6 class="mb-0">Product Catalog</h6>
                  <button class="btn btn-primary btn-sm" id="add-product-btn">
                    <i class="fa-solid fa-plus me-1"></i>Add Product
                  </button>
                </div>
                <div class="mb-3">
                  <input type="text" class="form-control" id="product-search" placeholder="Search products by name or SKU...">
                </div>
                <div id="products-list">Loading...</div>
              </div>

              <!-- Warehouses Tab -->
              <div class="tab-pane fade" id="warehouses" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6 class="mb-0">Warehouse Management</h6>
                  <button class="btn btn-primary btn-sm" id="add-warehouse-btn">
                    <i class="fa-solid fa-plus me-1"></i>Add Warehouse
                  </button>
                </div>
                <div id="warehouses-list">Loading...</div>
              </div>

              <!-- Archive Tab -->
              <div class="tab-pane fade" id="archive" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6 class="mb-0">Archived Products</h6>
                  <div>
                    <div class="form-check form-check-inline">
                      <input class="form-check-input" type="checkbox" id="show-restored" onchange="loadArchivedProducts()">
                      <label class="form-check-label" for="show-restored">Show Restored</label>
                    </div>
                  </div>
                </div>
                <div class="mb-3">
                  <input type="text" class="form-control" id="archive-search" placeholder="Search archived products...">
                </div>
                <div id="archive-list">Loading...</div>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  </main>

  <!-- Modals will be inserted here by JavaScript -->

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://unpkg.com/@zxing/library@latest/umd/index.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="assets/common.js?v=<?php echo time(); ?>"></script>
  <script src="assets/sws.js?v=<?php echo time(); ?>"></script>
</body>
</html>
