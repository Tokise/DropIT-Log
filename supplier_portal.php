<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>DropIT · Supplier Portal</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link href="assets/app.css" rel="stylesheet">
  <style>
    .stat-card { 
      transition: all 0.3s ease; 
      min-height: 120px;
      background: #fff;
    }
    .stat-card:hover { 
      transform: translateY(-3px);
      box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
    }
    .stat-card .card-body {
      padding: 1.5rem !important;
    }
    .metric-value { 
      font-size: 2rem; 
      font-weight: 700; 
      line-height: 1;
      margin-top: 0.5rem;
    }
    .stat-card h6 {
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .trend-up { color: #28a745; }
    .trend-down { color: #dc3545; }
  </style>
</head>
<body>
  <!-- Top Navigation -->
  <nav class="navbar navbar-dark bg-primary shadow-sm">
    <div class="container-fluid">
      <span class="navbar-brand mb-0 h1">
        <i class="fa-solid fa-truck me-2"></i>Supplier Portal
      </span>
      <div>
         
        <button class="btn btn-outline-light btn-sm" onclick="logout()">
          <i class="fa-solid fa-sign-out-alt me-1"></i>Logout
        </button>
      </div>
    </div>
  </nav>
  
  <!-- Notifications Dropdown -->
  <div id="notifications-dropdown" class="position-fixed bg-white shadow-lg rounded" style="display: none; top: 60px; right: 20px; width: 350px; max-height: 400px; overflow-y: auto; z-index: 1050;">
    <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
      <h6 class="mb-0">Notifications</h6>
      <button class="btn btn-sm btn-link text-decoration-none" onclick="markAllRead()">Mark all read</button>
    </div>
    <div id="notifications-list" class="p-2">
      <p class="text-center text-muted py-3">Loading...</p>
    </div>
  </div>

  <main class="container-fluid py-4">
    <!-- Dashboard Section -->
    <div class="row mb-4">
      <div class="col-12">
        <h4 class="mb-3"><i class="fa-solid fa-chart-line me-2 text-primary"></i>Dashboard Overview</h4>
      </div>
      
      <!-- Stat Cards -->
      <div class="col-md-3 mb-3">
        <div class="card stat-card shadow-sm border-start border-4 border-primary">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h6 class="text-muted mb-1">Total Orders</h6>
                <div class="metric-value text-dark" id="total-orders">0</div>
              </div>
              <i class="fa-solid fa-file-invoice fa-3x text-primary opacity-25"></i>
            </div>
          </div>
        </div>
      </div>
      
      <div class="col-md-3 mb-3">
        <div class="card stat-card shadow-sm border-start border-4 border-warning">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h6 class="text-muted mb-1">Pending Approval</h6>
                <div class="metric-value text-dark" id="pending-count">0</div>
              </div>
              <i class="fa-solid fa-clock fa-3x text-warning opacity-25"></i>
            </div>
          </div>
        </div>
      </div>
      
      <div class="col-md-3 mb-3">
        <div class="card stat-card shadow-sm border-start border-4 border-success">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h6 class="text-muted mb-1">Total Revenue</h6>
                <div class="metric-value text-dark" id="total-revenue">₱0</div>
              </div>
              <i class="fa-solid fa-peso-sign fa-3x text-success opacity-25"></i>
            </div>
          </div>
        </div>
      </div>
      
      <div class="col-md-3 mb-3">
        <div class="card stat-card shadow-sm border-start border-4 border-info">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h6 class="text-muted mb-1">Avg. Order Value</h6>
                <div class="metric-value text-dark" id="avg-order-value">₱0</div>
              </div>
              <i class="fa-solid fa-chart-bar fa-3x text-info opacity-25"></i>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Charts Row -->
    <div class="row mb-4">
      <div class="col-md-8 mb-3">
        <div class="card shadow-sm">
          <div class="card-body">
            <h6 class="card-title"><i class="fa-solid fa-chart-area me-2 text-primary"></i>Order Trends (Last 30 Days)</h6>
            <canvas id="orderTrendsChart" height="80"></canvas>
          </div>
        </div>
      </div>
      
      <div class="col-md-4 mb-3">
        <div class="card shadow-sm">
          <div class="card-body">
            <h6 class="card-title"><i class="fa-solid fa-chart-pie me-2 text-primary"></i>Order Status Distribution</h6>
            <canvas id="statusPieChart"></canvas>
          </div>
        </div>
      </div>
    </div>

    <!-- Performance Metrics -->
    <div class="row mb-4">
      <div class="col-md-6 mb-3">
        <div class="card shadow-sm">
          <div class="card-body">
            <h6 class="card-title"><i class="fa-solid fa-tachometer-alt me-2 text-primary"></i>Performance Metrics</h6>
            <div class="row mt-3">
              <div class="col-6 mb-3">
                <div class="border-start border-4 border-success ps-3">
                  <small class="text-muted">Approval Rate</small>
                  <h4 id="approval-rate" class="mb-0">0%</h4>
                </div>
              </div>
              <div class="col-6 mb-3">
                <div class="border-start border-4 border-info ps-3">
                  <small class="text-muted">Avg. Response Time</small>
                  <h4 id="avg-response-time" class="mb-0">0h</h4>
                </div>
              </div>
              <div class="col-6 mb-3">
                <div class="border-start border-4 border-warning ps-3">
                  <small class="text-muted">On-Time Delivery</small>
                  <h4 id="on-time-delivery" class="mb-0">0%</h4>
                </div>
              </div>
              <div class="col-6 mb-3">
                <div class="border-start border-4 border-danger ps-3">
                  <small class="text-muted">Rejection Rate</small>
                  <h4 id="rejection-rate" class="mb-0">0%</h4>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <div class="col-md-6 mb-3">
        <div class="card shadow-sm">
          <div class="card-body">
            <h6 class="card-title"><i class="fa-solid fa-list-check me-2 text-primary"></i>Recent Activity</h6>
            <div id="recent-activity" class="mt-3" style="max-height: 250px; overflow-y: auto;">
              <p class="text-muted text-center py-3">Loading...</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Purchase Orders Section -->
    <div class="row">
      <div class="col-12">
        <div class="card shadow-sm">
          <div class="card-body">
            <h5 class="card-title mb-4">
              <i class="fa-solid fa-file-invoice me-2 text-primary"></i>Purchase Orders Requiring Action
            </h5>
            
            <!-- Filter Tabs -->
            <ul class="nav nav-pills mb-3" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="pending-tab" data-bs-toggle="pill" data-bs-target="#pending" type="button" role="tab">
                  <i class="fa-solid fa-clock me-1"></i>Pending Approval
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="approved-tab" data-bs-toggle="pill" data-bs-target="#approved" type="button" role="tab">
                  <i class="fa-solid fa-check me-1"></i>Approved
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="all-tab" data-bs-toggle="pill" data-bs-target="#all" type="button" role="tab">
                  <i class="fa-solid fa-list me-1"></i>All Orders
                </button>
              </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content">
              <div class="tab-pane fade show active" id="pending" role="tabpanel">
                <div id="pending-pos-list">Loading...</div>
              </div>
              <div class="tab-pane fade" id="approved" role="tabpanel">
                <div id="approved-pos-list">Loading...</div>
              </div>
              <div class="tab-pane fade" id="all" role="tabpanel">
                <div id="all-pos-list">Loading...</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <script src="assets/common.js"></script>
  <script src="assets/supplier_portal.js"></script>
</body>
</html>
