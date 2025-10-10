<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>DropIT · PSM</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link href="assets/app.css" rel="stylesheet">
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
            <h5 class="card-title mb-5"><i class="fa-solid fa-handshake me-2 text-primary"></i>PSM – Procurement & Supplier Management</h5>
            
            <!-- Navigation Tabs -->
            <ul class="nav nav-pills mb-4" id="psmTabs" role="tablist" style="margin-top: 2rem;">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="suppliers-tab" data-bs-toggle="pill" data-bs-target="#suppliers" type="button" role="tab">
                  <i class="fa-solid fa-building me-1"></i>Suppliers
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="purchase-orders-tab" data-bs-toggle="pill" data-bs-target="#purchase-orders" type="button" role="tab">
                  <i class="fa-solid fa-file-invoice me-1"></i>Purchase Orders
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="reorder-tab" data-bs-toggle="pill" data-bs-target="#reorder" type="button" role="tab">
                  <i class="fa-solid fa-rotate me-1"></i>Reorder Management
                </button>
              </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content" id="psmTabContent">
              <!-- Suppliers Tab -->
              <div class="tab-pane fade show active" id="suppliers" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6 class="mb-0">Supplier Directory</h6>
                  <button class="btn btn-primary btn-sm" id="add-supplier-btn">
                    <i class="fa-solid fa-plus me-1"></i>Add Supplier
                  </button>
                </div>
                <div class="mb-3">
                  <input type="text" class="form-control" id="supplier-search" placeholder="Search suppliers by name or code...">
                </div>
                <div id="suppliers-list">Loading...</div>
              </div>

              <!-- Purchase Orders Tab -->
              <div class="tab-pane fade" id="purchase-orders" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6 class="mb-0">Purchase Orders</h6>
                  <button class="btn btn-primary btn-sm" id="create-po-btn">
                    <i class="fa-solid fa-plus me-1"></i>Create Purchase Order
                  </button>
                </div>
                <div class="mb-3">
                  <div class="btn-group btn-group-sm" role="group">
                    <input type="radio" class="btn-check" name="po-filter" id="po-all" value="" checked>
                    <label class="btn btn-outline-primary" for="po-all">All</label>
                    
                    <input type="radio" class="btn-check" name="po-filter" id="po-draft" value="draft">
                    <label class="btn btn-outline-secondary" for="po-draft">Draft</label>
                    
                    <input type="radio" class="btn-check" name="po-filter" id="po-pending" value="pending_approval">
                    <label class="btn btn-outline-warning" for="po-pending">Pending</label>
                    
                    <input type="radio" class="btn-check" name="po-filter" id="po-approved" value="approved">
                    <label class="btn btn-outline-success" for="po-approved">Approved</label>
                    
                    <input type="radio" class="btn-check" name="po-filter" id="po-received" value="received">
                    <label class="btn btn-outline-info" for="po-received">Received</label>

                    <input type="radio" class="btn-check" name="po-filter" id="po-archived" value="archived">
                    <label class="btn btn-outline-danger" for="po-archived">Archived</label>
                  </div>
                </div>
                <div id="purchase-orders-list">Loading...</div>
              </div>

              <!-- Reorder Management Tab -->
              <div class="tab-pane fade" id="reorder" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6 class="mb-0">Reorder Alerts & Approvals</h6>
                  <button class="btn btn-success btn-sm" id="approve-all-btn">
                    <i class="fa-solid fa-check-double me-1"></i>Approve All
                  </button>
                </div>
                <div id="reorder-list">Loading...</div>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="assets/common.js"></script>
  <script src="assets/psm.js"></script>
</body>
</html>
