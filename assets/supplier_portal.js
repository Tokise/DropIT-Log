(function() {
  'use strict';

  // Load POs for supplier
  async function loadSupplierPOs(status = '') {
    try {
      const url = status 
        ? `api/supplier_pos.php?status=${status}`
        : `api/supplier_pos.php`;
      
      const response = await Api.get(url);
      
      // Map status to correct container ID
      let listId;
      if (status === 'pending_approval') {
        listId = 'pending-pos-list';
      } else if (status === 'approved') {
        listId = 'approved-pos-list';
      } else {
        listId = 'all-pos-list';
      }
      
      const container = document.getElementById(listId);
      
      if (response.data.items.length === 0) {
        container.innerHTML = '<div class="alert alert-info">No purchase orders found</div>';
        return;
      }
      
      const rows = response.data.items.map(po => {
        const statusColors = {
          'draft': 'secondary',
          'pending_approval': 'warning',
          'approved': 'success',
          'sent': 'primary',
          'received': 'info'
        };
        
        const statusLabels = {
          'draft': 'DRAFT',
          'pending_approval': 'PENDING',
          'approved': 'APPROVED',
          'sent': 'SENT',
          'received': 'RECEIVED'
        };
        
        let actionButton = '';
        if (po.status === 'pending_approval') {
          actionButton = `
            <button class="btn btn-success btn-sm me-1" onclick="changeSupplierPOStatus(${po.id}, 'approved')">
              <i class="fa-solid fa-check me-1"></i>Approve
            </button>
            <button class="btn btn-danger btn-sm" onclick="changeSupplierPOStatus(${po.id}, 'draft')">
              <i class="fa-solid fa-times me-1"></i>Reject
            </button>
          `;
        } else if (po.status === 'approved') {
          actionButton = `
            <button class="btn btn-primary btn-sm" onclick="changeSupplierPOStatus(${po.id}, 'sent')">
              <i class="fa-solid fa-truck me-1"></i>Mark as Sent
            </button>
          `;
        } else {
          actionButton = '<span class="text-muted small">No action needed</span>';
        }
        
        return `
          <tr>
            <td><code>${po.po_number}</code></td>
            <td>${new Date(po.order_date).toLocaleDateString()}</td>
            <td>${po.expected_delivery_date ? new Date(po.expected_delivery_date).toLocaleDateString() : '-'}</td>
            <td><strong>₱${parseFloat(po.total_amount).toFixed(2)}</strong></td>
            <td><span class="badge bg-${statusColors[po.status] || 'secondary'}">${statusLabels[po.status] || po.status.toUpperCase()}</span></td>
            <td>
              <button class="btn btn-outline-primary btn-sm me-1" onclick="viewSupplierPODetails(${po.id})">
                <i class="fa-solid fa-eye"></i>
              </button>
              ${actionButton}
            </td>
          </tr>
        `;
      }).join('');
      
      container.innerHTML = `
        <div class="table-responsive">
          <table class="table table-hover">
            <thead>
              <tr>
                <th>PO Number</th>
                <th>Order Date</th>
                <th>Expected Delivery</th>
                <th>Total Amount</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      `;
    } catch (err) {
      console.error('Failed to load POs:', err);
      
      // Map status to correct container ID for error display
      let listId;
      if (status === 'pending_approval') {
        listId = 'pending-pos-list';
      } else if (status === 'approved') {
        listId = 'approved-pos-list';
      } else {
        listId = 'all-pos-list';
      }
      
      const container = document.getElementById(listId);
      if (container) {
        container.innerHTML = `<div class="alert alert-danger">Failed to load: ${err.message}</div>`;
      }
    }
  }

  // View PO Details
  window.viewSupplierPODetails = async function(poId) {
    try {
      const response = await Api.get(`api/supplier_pos.php?id=${poId}`);
      const po = response.data;
      
      const itemsHtml = po.items && po.items.length > 0 ? `
        <h6 class="mt-3">Line Items:</h6>
        <table class="table table-sm">
          <thead>
            <tr><th>Product</th><th>SKU</th><th>Qty</th><th>Price</th><th>Total</th></tr>
          </thead>
          <tbody>
            ${po.items.map(item => `
              <tr>
                <td>${item.name}</td>
                <td><code>${item.sku}</code></td>
                <td>${item.quantity}</td>
                <td>₱${parseFloat(item.unit_price).toFixed(2)}</td>
                <td><strong>₱${(item.quantity * item.unit_price).toFixed(2)}</strong></td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      ` : '';
      
      Swal.fire({
        title: `Purchase Order ${po.po_number}`,
        html: `
          <div class="text-start">
            <p><strong>Order Date:</strong> ${new Date(po.order_date).toLocaleDateString()}</p>
            <p><strong>Expected Delivery:</strong> ${po.expected_delivery_date ? new Date(po.expected_delivery_date).toLocaleDateString() : '-'}</p>
            <p><strong>Warehouse:</strong> ${po.warehouse_name}</p>
            <p><strong>Total Amount:</strong> <span class="h5 text-success">₱${parseFloat(po.total_amount).toFixed(2)}</span></p>
            <p><strong>Status:</strong> <span class="badge bg-primary">${po.status.toUpperCase()}</span></p>
            ${itemsHtml}
            ${po.notes ? `<div class="alert alert-secondary small mt-3"><strong>Notes:</strong> ${po.notes}</div>` : ''}
          </div>
        `,
        width: '700px',
        showCloseButton: true,
        showConfirmButton: false
      });
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: 'Failed to Load PO',
        text: err.message
      });
    }
  };

  // Approve PO
  window.approvePO = async function(poId, poNumber) {
    const result = await Swal.fire({
      title: 'Approve Purchase Order?',
      html: `
        <p>Approve PO <strong>${poNumber}</strong>?</p>
        <div class="text-start mt-3">
          <label class="form-label">Confirmation Notes:</label>
          <textarea id="approval-notes" class="form-control" rows="3" placeholder="Add any notes about this approval..."></textarea>
        </div>
      `,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, Approve',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#28a745',
      preConfirm: () => {
        return {
          notes: document.getElementById('approval-notes').value.trim()
        };
      }
    });
    
    if (result.isConfirmed) {
      try {
        Swal.fire({
          title: 'Processing...',
          html: '<div class="spinner-border text-primary"></div>',
          showConfirmButton: false,
          allowOutsideClick: false
        });
        
        await Api.send('api/po_status.php', 'POST', {
          po_id: poId,
          new_status: 'approved',
          notes: result.value.notes,
          user_type: 'supplier'
        });
        
        await Swal.fire({
          icon: 'success',
          title: 'PO Approved!',
          text: `Purchase order ${poNumber} has been approved`,
          timer: 2000
        });
        
        // Reload lists
        loadSupplierPOs('pending');
        loadSupplierPOs('approved');
        loadSupplierPOs('');
        
      } catch (err) {
        Swal.fire({
          icon: 'error',
          title: 'Approval Failed',
          text: err.message
        });
      }
    }
  };

  // Reject PO
  window.rejectPO = async function(poId, poNumber) {
    const result = await Swal.fire({
      title: 'Reject Purchase Order?',
      html: `
        <p>Reject PO <strong>${poNumber}</strong> and return to draft?</p>
        <div class="text-start mt-3">
          <label class="form-label">Reason for Rejection <span class="text-danger">*</span>:</label>
          <textarea id="rejection-notes" class="form-control" rows="3" placeholder="Please provide a reason for rejection..."></textarea>
        </div>
      `,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, Reject',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#dc3545',
      preConfirm: () => {
        const notes = document.getElementById('rejection-notes').value.trim();
        if (!notes) {
          Swal.showValidationMessage('Please provide a reason for rejection');
          return false;
        }
        return { notes };
      }
    });
    
    if (result.isConfirmed) {
      try {
        Swal.fire({
          title: 'Processing...',
          html: '<div class="spinner-border text-primary"></div>',
          showConfirmButton: false,
          allowOutsideClick: false
        });
        
        await Api.send('api/po_status.php', 'POST', {
          po_id: poId,
          new_status: 'draft',
          notes: `REJECTED: ${result.value.notes}`,
          user_type: 'supplier'
        });
        
        await Swal.fire({
          icon: 'success',
          title: 'PO Rejected',
          text: `Purchase order ${poNumber} has been returned to draft`,
          timer: 2000
        });
        
        // Reload lists
        loadSupplierPOs('pending');
        loadSupplierPOs('');
        
      } catch (err) {
        Swal.fire({
          icon: 'error',
          title: 'Rejection Failed',
          text: err.message
        });
      }
    }
  };

  // Change Supplier PO Status (unified function)
  window.changeSupplierPOStatus = async function(poId, newStatus) {
    const statusLabels = {
      'approved': 'Approve',
      'draft': 'Reject',
      'sent': 'Mark as Sent'
    };
    
    const actionLabel = statusLabels[newStatus] || 'Change Status';
    
    const result = await Swal.fire({
      title: `${actionLabel} Purchase Order?`,
      text: `Are you sure you want to ${actionLabel.toLowerCase()} this order?`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: `Yes, ${actionLabel}`,
      cancelButtonText: 'Cancel'
    });
    
    if (result.isConfirmed) {
      try {
        await Api.send('api/po_status.php', 'POST', {
          po_id: poId,
          new_status: newStatus,
          user_type: 'supplier'
        });
        
        await Swal.fire({
          icon: 'success',
          title: 'Status Updated!',
          text: `Purchase order status changed successfully`,
          timer: 2000
        });
        
        // Reload lists
        loadSupplierPOs('pending_approval');
        loadSupplierPOs('approved');
        loadSupplierPOs('');
        
      } catch (err) {
        Swal.fire({
          icon: 'error',
          title: 'Status Change Failed',
          text: err.message
        });
      }
    }
  };

  // Load Supplier Notifications
  async function loadSupplierNotifications() {
    try {
      const response = await Api.get('api/supplier_notifications.php');
      const notifications = response.data.notifications || [];
      const container = document.getElementById('notifications-list');
      
      if (notifications.length === 0) {
        container.innerHTML = '<p class="text-center text-muted py-3">No notifications</p>';
        return;
      }
      
      container.innerHTML = notifications.map(notif => {
        const isUnread = notif.is_read == 0;
        const bgClass = isUnread ? 'bg-light' : '';
        const timeAgo = new Date(notif.sent_at).toLocaleString();
        
        return `
          <div class="notification-item p-2 border-bottom ${bgClass}" onclick="markNotificationRead(${notif.id})">
            <div class="d-flex justify-content-between">
              <strong class="small">${notif.title}</strong>
              <small class="text-muted">${timeAgo}</small>
            </div>
            <p class="small mb-0 mt-1">${notif.message}</p>
            ${notif.po_number ? `<small class="text-primary">PO: ${notif.po_number}</small>` : ''}
          </div>
        `;
      }).join('');
      
      // Update badge
      const unreadCount = notifications.filter(n => n.is_read == 0).length;
      updateNotificationBadge(unreadCount);
    } catch (err) {
      console.error('Failed to load notifications:', err);
    }
  }
  
  function updateNotificationBadge(count) {
    const badge = document.getElementById('notif-badge');
    if (count > 0) {
      badge.textContent = count;
      badge.style.display = 'inline-block';
    } else {
      badge.style.display = 'none';
    }
  }
  
  window.toggleNotifications = function() {
    const dropdown = document.getElementById('notifications-dropdown');
    if (dropdown.style.display === 'none') {
      dropdown.style.display = 'block';
      loadSupplierNotifications();
    } else {
      dropdown.style.display = 'none';
    }
  };
  
  window.markNotificationRead = async function(notifId) {
    try {
      await Api.send('api/supplier_notifications.php', 'POST', { notification_id: notifId });
      loadSupplierNotifications();
    } catch (err) {
      console.error('Failed to mark notification as read:', err);
    }
  };
  
  window.markAllRead = async function() {
    try {
      await Api.send('api/supplier_notifications.php', 'POST', { mark_all_read: true });
      loadSupplierNotifications();
    } catch (err) {
      console.error('Failed to mark all as read:', err);
    }
  };
  
  // Load notification count periodically
  setInterval(async () => {
    try {
      const response = await Api.get('api/supplier_notifications.php?count_only=1');
      updateNotificationBadge(response.data.unread);
    } catch (err) {
      console.error('Failed to load notification count:', err);
    }
  }, 30000); // Every 30 seconds

  // Logout
  window.logout = function() {
    window.location.href = 'api/logout.php';
  };

  // Load Dashboard Data
  let orderTrendsChart = null;
  let statusPieChart = null;
  
  async function loadDashboard() {
    try {
      const response = await Api.get('api/supplier_dashboard.php');
      const data = response.data;
      
      // Update metric cards
      document.getElementById('total-orders').textContent = data.metrics.total_orders;
      document.getElementById('pending-count').textContent = data.metrics.pending_count;
      document.getElementById('total-revenue').textContent = `₱${parseFloat(data.metrics.total_revenue).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
      document.getElementById('avg-order-value').textContent = `₱${parseFloat(data.metrics.avg_order_value).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
      
      // Update performance metrics
      document.getElementById('approval-rate').textContent = `${data.metrics.approval_rate}%`;
      document.getElementById('rejection-rate').textContent = `${data.metrics.rejection_rate}%`;
      document.getElementById('avg-response-time').textContent = `${data.metrics.avg_response_time}h`;
      document.getElementById('on-time-delivery').textContent = `${data.metrics.on_time_delivery}%`;
      
      // Render Order Trends Chart
      renderOrderTrendsChart(data.order_trends);
      
      // Render Status Pie Chart
      renderStatusPieChart(data.status_distribution);
      
      // Render Recent Activity
      renderRecentActivity(data.recent_activity);
      
    } catch (err) {
      console.error('Failed to load dashboard:', err);
    }
  }
  
  function renderOrderTrendsChart(trends) {
    const ctx = document.getElementById('orderTrendsChart');
    if (!ctx) return;
    
    const labels = trends.map(t => new Date(t.date).toLocaleDateString('en-US', {month: 'short', day: 'numeric'}));
    const orderCounts = trends.map(t => parseInt(t.count));
    const revenues = trends.map(t => parseFloat(t.revenue));
    
    if (orderTrendsChart) {
      orderTrendsChart.destroy();
    }
    
    orderTrendsChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Orders',
            data: orderCounts,
            borderColor: '#667eea',
            backgroundColor: 'rgba(102, 126, 234, 0.1)',
            tension: 0.4,
            yAxisID: 'y'
          },
          {
            label: 'Revenue (₱)',
            data: revenues,
            borderColor: '#43e97b',
            backgroundColor: 'rgba(67, 233, 123, 0.1)',
            tension: 0.4,
            yAxisID: 'y1'
          }
        ]
      },
      options: {
        responsive: true,
        interaction: {
          mode: 'index',
          intersect: false
        },
        scales: {
          y: {
            type: 'linear',
            display: true,
            position: 'left',
            title: {
              display: true,
              text: 'Orders'
            }
          },
          y1: {
            type: 'linear',
            display: true,
            position: 'right',
            title: {
              display: true,
              text: 'Revenue (₱)'
            },
            grid: {
              drawOnChartArea: false
            }
          }
        }
      }
    });
  }
  
  function renderStatusPieChart(distribution) {
    const ctx = document.getElementById('statusPieChart');
    if (!ctx) return;
    
    const statusLabels = {
      'draft': 'Draft',
      'pending_approval': 'Pending',
      'approved': 'Approved',
      'sent': 'Sent',
      'partially_received': 'Partial',
      'received': 'Received'
    };
    
    const statusColors = {
      'draft': '#6c757d',
      'pending_approval': '#ffc107',
      'approved': '#28a745',
      'sent': '#007bff',
      'partially_received': '#17a2b8',
      'received': '#20c997'
    };
    
    const labels = distribution.map(d => statusLabels[d.status] || d.status);
    const data = distribution.map(d => parseInt(d.count));
    const colors = distribution.map(d => statusColors[d.status] || '#6c757d');
    
    if (statusPieChart) {
      statusPieChart.destroy();
    }
    
    statusPieChart = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: labels,
        datasets: [{
          data: data,
          backgroundColor: colors,
          borderWidth: 2,
          borderColor: '#fff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
          legend: {
            position: 'bottom'
          }
        }
      }
    });
  }
  
  function renderRecentActivity(activities) {
    const container = document.getElementById('recent-activity');
    if (!container) return;
    
    if (activities.length === 0) {
      container.innerHTML = '<p class="text-muted text-center py-3">No recent activity</p>';
      return;
    }
    
    const statusIcons = {
      'draft': 'fa-file',
      'pending_approval': 'fa-clock',
      'approved': 'fa-check-circle',
      'sent': 'fa-truck',
      'partially_received': 'fa-box-open',
      'received': 'fa-check-double'
    };
    
    const statusColors = {
      'draft': 'secondary',
      'pending_approval': 'warning',
      'approved': 'success',
      'sent': 'primary',
      'partially_received': 'info',
      'received': 'success'
    };
    
    container.innerHTML = activities.map(activity => {
      const timeAgo = new Date(activity.created_at).toLocaleString();
      const icon = statusIcons[activity.to_status] || 'fa-circle';
      const color = statusColors[activity.to_status] || 'secondary';
      
      return `
        <div class="d-flex align-items-start mb-3 pb-3 border-bottom">
          <div class="me-3">
            <i class="fa-solid ${icon} text-${color}"></i>
          </div>
          <div class="flex-grow-1">
            <div class="d-flex justify-content-between">
              <strong class="small">${activity.po_number}</strong>
              <small class="text-muted">${timeAgo}</small>
            </div>
            <p class="small mb-0 text-muted">
              Status changed from <span class="badge badge-sm bg-secondary">${activity.from_status || 'N/A'}</span> 
              to <span class="badge badge-sm bg-${color}">${activity.to_status}</span>
            </p>
            ${activity.notes ? `<p class="small mb-0 mt-1 text-muted fst-italic">${activity.notes}</p>` : ''}
          </div>
        </div>
      `;
    }).join('');
  }

  // Initialize
  document.addEventListener('DOMContentLoaded', () => {
    loadDashboard();
    loadSupplierPOs('pending_approval');
    loadSupplierNotifications();
    
    // Tab change events
    document.getElementById('approved-tab').addEventListener('shown.bs.tab', () => {
      loadSupplierPOs('approved');
    });
    
    document.getElementById('all-tab').addEventListener('shown.bs.tab', () => {
      loadSupplierPOs('');
    });
    
    // Refresh dashboard every 5 minutes
    setInterval(loadDashboard, 300000);
  });

})();
