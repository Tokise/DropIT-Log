(function() {
  'use strict';

  // Pagination variables
  let currentSuppliersPage = 1;
  let currentPOPage = 1;
  let currentReorderPage = 1;
  const itemsPerPage = 15;

  // Load Suppliers
  async function loadSuppliers(search = '', page = 1) {
    try {
      currentSuppliersPage = page;
      const url = search 
        ? `api/suppliers.php?q=${encodeURIComponent(search)}&page=${page}&limit=${itemsPerPage}`
        : `api/suppliers.php?page=${page}&limit=${itemsPerPage}`;
      
      const response = await Api.get(url);
      const container = document.getElementById('suppliers-list');
      
      if (response.data.items.length === 0) {
        container.innerHTML = '<div class="text-muted">No suppliers found</div>';
        return;
      }
      
      const rows = response.data.items.map(supplier => `
        <tr>
          <td><strong>${supplier.name}</strong></td>
          <td><code>${supplier.code}</code></td>
          <td>${supplier.contact_person || '-'}</td>
          <td>${supplier.email || '-'}</td>
          <td>${supplier.phone || '-'}</td>
          <td>${supplier.country || '-'}</td>
          <td><span class="badge ${supplier.is_active ? 'bg-success' : 'bg-secondary'}">${supplier.is_active ? 'Active' : 'Inactive'}</span></td>
          <td>
            <button class="btn btn-sm btn-outline-primary me-1" onclick="viewSupplierDetails(${supplier.id})" title="View Details">
              <i class="fa-solid fa-eye"></i>
            </button>
            <button class="btn btn-sm btn-outline-success" onclick="createPOForSupplier(${supplier.id}, '${supplier.name.replace(/'/g, "\\'")}')">
              <i class="fa-solid fa-file-invoice"></i>
            </button>
          </td>
        </tr>
      `).join('');
      
      // Pagination
      const totalPages = Math.ceil(response.data.total / itemsPerPage);
      let paginationHtml = '';
      
      if (totalPages > 1) {
        paginationHtml = '<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">';
        
        paginationHtml += `<li class="page-item ${currentSuppliersPage === 1 ? 'disabled' : ''}">
          <a class="page-link" href="#" onclick="loadSuppliers('', ${currentSuppliersPage - 1}); return false;">Previous</a>
        </li>`;
        
        for (let i = 1; i <= totalPages; i++) {
          if (i === 1 || i === totalPages || (i >= currentSuppliersPage - 2 && i <= currentSuppliersPage + 2)) {
            paginationHtml += `<li class="page-item ${i === currentSuppliersPage ? 'active' : ''}">
              <a class="page-link" href="#" onclick="loadSuppliers('', ${i}); return false;">${i}</a>
            </li>`;
          } else if (i === currentSuppliersPage - 3 || i === currentSuppliersPage + 3) {
            paginationHtml += '<li class="page-item disabled"><span class="page-link">...</span></li>';
          }
        }
        
        paginationHtml += `<li class="page-item ${currentSuppliersPage === totalPages ? 'disabled' : ''}">
          <a class="page-link" href="#" onclick="loadSuppliers('', ${currentSuppliersPage + 1}); return false;">Next</a>
        </li>`;
        
        paginationHtml += '</ul></nav>';
      }
      
      container.innerHTML = `
        <div class="table-responsive">
          <table class="table table-sm table-hover">
            <thead>
              <tr>
                <th>Name</th>
                <th>Code</th>
                <th>Contact Person</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Country</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        ${paginationHtml}
        <div class="text-muted small text-center mt-2">
          Showing ${((currentSuppliersPage - 1) * itemsPerPage) + 1} to ${Math.min(currentSuppliersPage * itemsPerPage, response.data.total)} of ${response.data.total} suppliers
        </div>
      `;
    } catch (err) {
      document.getElementById('suppliers-list').innerHTML = `<div class="alert alert-danger">Failed to load suppliers: ${err.message}</div>`;
    }
  }

  // Make loadSuppliers global
  window.loadSuppliers = loadSuppliers;

  // Load Purchase Orders
  async function loadPurchaseOrders(status = '', page = 1) {
    try {
      currentPOPage = page;
      const timestamp = new Date().getTime(); // Cache buster
      const url = status 
        ? `api/purchase_orders.php?status=${status}&page=${page}&limit=${itemsPerPage}&_=${timestamp}`
        : `api/purchase_orders.php?page=${page}&limit=${itemsPerPage}&_=${timestamp}`;
      
      const response = await Api.get(url);
      const container = document.getElementById('purchase-orders-list');
      
      // Debug: log what we received
      console.log('PO API Response:', response.data);
      console.log('Filter status:', status);
      console.log('Number of POs:', response.data.items.length);
      response.data.items.forEach(po => {
        console.log(`PO ${po.po_number}: Status = "${po.status}"`);
      });
      
      if (response.data.items.length === 0) {
        container.innerHTML = '<div class="text-muted">No purchase orders found</div>';
        return;
      }
      
      const rows = response.data.items.map(po => {
        const statusColors = {
          'draft': 'secondary',
          'pending_approval': 'warning',
          'approved': 'success',
          'sent': 'primary',
          'partially_received': 'info',
          'received': 'success',
          'cancelled': 'danger'
        };
        
        const statusLabels = {
          'draft': 'DRAFT',
          'pending_approval': 'PENDING',
          'approved': 'APPROVED',
          'sent': 'SENT',
          'partially_received': 'PARTIAL',
          'received': 'RECEIVED',
          'cancelled': 'CANCELLED'
        };
        
        const orderDate = po.order_date ? new Date(po.order_date).toLocaleDateString() : 
                         (po.created_at ? new Date(po.created_at).toLocaleDateString() : '-');
        
        const status = po.status || 'draft';
        const statusColor = statusColors[status] || 'secondary';
        const statusLabel = statusLabels[status] || status.toUpperCase();
        
        return `
          <tr>
            <td><code>${po.po_number || 'N/A'}</code></td>
            <td>${po.supplier_name || 'Unknown'}</td>
            <td>${orderDate}</td>
            <td>${po.expected_delivery_date ? new Date(po.expected_delivery_date).toLocaleDateString() : '-'}</td>
            <td><strong>₱${parseFloat(po.total_amount || 0).toFixed(2)}</strong></td>
            <td><span class="badge bg-${statusColor}">${statusLabel}</span></td>
            <td>
              <button class="btn btn-sm btn-outline-primary" onclick="viewPODetails(${po.id})" title="View Details">
                <i class="fa-solid fa-eye"></i>
              </button>
              <button class="btn btn-sm btn-outline-danger" onclick="archivePO(${po.id})" title="Archive">
                <i class="fa-solid fa-trash"></i>
              </button>
            </td>
          </tr>
        `;
      }).join('');
      
      // Pagination
      const totalPages = Math.ceil(response.data.total / itemsPerPage);
      let paginationHtml = '';
      
      if (totalPages > 1) {
        paginationHtml = '<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">';
        
        paginationHtml += `<li class="page-item ${currentPOPage === 1 ? 'disabled' : ''}">
          <a class="page-link" href="#" onclick="loadPurchaseOrders('${status}', ${currentPOPage - 1}); return false;">Previous</a>
        </li>`;
        
        for (let i = 1; i <= totalPages; i++) {
          if (i === 1 || i === totalPages || (i >= currentPOPage - 2 && i <= currentPOPage + 2)) {
            paginationHtml += `<li class="page-item ${i === currentPOPage ? 'active' : ''}">
              <a class="page-link" href="#" onclick="loadPurchaseOrders('${status}', ${i}); return false;">${i}</a>
            </li>`;
          } else if (i === currentPOPage - 3 || i === currentPOPage + 3) {
            paginationHtml += '<li class="page-item disabled"><span class="page-link">...</span></li>';
          }
        }
        
        paginationHtml += `<li class="page-item ${currentPOPage === totalPages ? 'disabled' : ''}">
          <a class="page-link" href="#" onclick="loadPurchaseOrders('${status}', ${currentPOPage + 1}); return false;">Next</a>
        </li>`;
        
        paginationHtml += '</ul></nav>';
      }
      
      container.innerHTML = `
        <div class="table-responsive">
          <table class="table table-sm table-hover">
            <thead>
              <tr>
                <th>PO Number</th>
                <th>Supplier</th>
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
        ${paginationHtml}
        <div class="text-muted small text-center mt-2">
          Showing ${((currentPOPage - 1) * itemsPerPage) + 1} to ${Math.min(currentPOPage * itemsPerPage, response.data.total)} of ${response.data.total} purchase orders
        </div>
      `;
    } catch (err) {
      document.getElementById('purchase-orders-list').innerHTML = `<div class="alert alert-danger">Failed to load purchase orders: ${err.message}</div>`;
    }
  }

  // Make loadPurchaseOrders global
  window.loadPurchaseOrders = loadPurchaseOrders;

  // Load Reorder Alerts
  async function loadReorderAlerts(page = 1) {
    try {
      currentReorderPage = page;
      const response = await Api.get(`api/reorder_approvals.php?page=${page}&limit=${itemsPerPage}`);
      const container = document.getElementById('reorder-list');
      
      if (response.data.items.length === 0) {
        container.innerHTML = '<div class="alert alert-info"><i class="fa-solid fa-check-circle me-2"></i>No products need reordering at this time</div>';
        return;
      }
      
      const rows = response.data.items.map(item => {
        const urgency = item.current_stock <= 0 ? 'danger' : 
                       item.current_stock <= item.reorder_point / 2 ? 'warning' : 'info';
        
        return `
          <tr>
            <td><strong>${item.product_name}</strong><br><small class="text-muted">${item.sku}</small></td>
            <td><span class="badge bg-${urgency}">${item.current_stock}</span></td>
            <td>${item.reorder_point}</td>
            <td>${item.reorder_quantity}</td>
            <td>${item.lead_time_days} days</td>
            <td>${item.supplier_name || '-'}</td>
            <td>
              <button class="btn btn-sm btn-success" onclick="approveReorder(${item.product_id}, '${item.product_name.replace(/'/g, "\\'")}', ${item.reorder_quantity})">
                <i class="fa-solid fa-check me-1"></i>Approve
              </button>
            </td>
          </tr>
        `;
      }).join('');
      
      // Pagination
      const totalPages = Math.ceil(response.data.total / itemsPerPage);
      let paginationHtml = '';
      
      if (totalPages > 1) {
        paginationHtml = '<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">';
        
        paginationHtml += `<li class="page-item ${currentReorderPage === 1 ? 'disabled' : ''}">
          <a class="page-link" href="#" onclick="loadReorderAlerts(${currentReorderPage - 1}); return false;">Previous</a>
        </li>`;
        
        for (let i = 1; i <= totalPages; i++) {
          if (i === 1 || i === totalPages || (i >= currentReorderPage - 2 && i <= currentReorderPage + 2)) {
            paginationHtml += `<li class="page-item ${i === currentReorderPage ? 'active' : ''}">
              <a class="page-link" href="#" onclick="loadReorderAlerts(${i}); return false;">${i}</a>
            </li>`;
          } else if (i === currentReorderPage - 3 || i === currentReorderPage + 3) {
            paginationHtml += '<li class="page-item disabled"><span class="page-link">...</span></li>';
          }
        }
        
        paginationHtml += `<li class="page-item ${currentReorderPage === totalPages ? 'disabled' : ''}">
          <a class="page-link" href="#" onclick="loadReorderAlerts(${currentReorderPage + 1}); return false;">Next</a>
        </li>`;
        
        paginationHtml += '</ul></nav>';
      }
      
      container.innerHTML = `
        <div class="alert alert-warning">
          <i class="fa-solid fa-exclamation-triangle me-2"></i>
          <strong>${response.data.total} products</strong> are below reorder point and need restocking
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-hover">
            <thead>
              <tr>
                <th>Product</th>
                <th>Current Stock</th>
                <th>Reorder Point</th>
                <th>Reorder Qty</th>
                <th>Lead Time</th>
                <th>Preferred Supplier</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        ${paginationHtml}
        <div class="text-muted small text-center mt-2">
          Showing ${((currentReorderPage - 1) * itemsPerPage) + 1} to ${Math.min(currentReorderPage * itemsPerPage, response.data.total)} of ${response.data.total} items
        </div>
      `;
    } catch (err) {
      document.getElementById('reorder-list').innerHTML = `<div class="alert alert-danger">Failed to load reorder alerts: ${err.message}</div>`;
    }
  }

  // Make loadReorderAlerts global
  window.loadReorderAlerts = loadReorderAlerts;

  // View Supplier Details
  window.viewSupplierDetails = async function(supplierId) {
    try {
      const response = await Api.get(`api/suppliers.php?id=${supplierId}`);
      const supplier = response.data;
      
      Swal.fire({
        title: 'Supplier Details',
        html: `
          <div class="text-start">
            <h6>${supplier.name}</h6>
            <hr>
            <p><strong>Code:</strong> ${supplier.code}</p>
            <p><strong>Contact Person:</strong> ${supplier.contact_person || '-'}</p>
            <p><strong>Email:</strong> ${supplier.email || '-'}</p>
            <p><strong>Phone:</strong> ${supplier.phone || '-'}</p>
            <p><strong>Address:</strong> ${supplier.address || '-'}</p>
            <p><strong>Country:</strong> ${supplier.country || '-'}</p>
            <p><strong>Status:</strong> <span class="badge ${supplier.is_active ? 'bg-success' : 'bg-secondary'}">${supplier.is_active ? 'Active' : 'Inactive'}</span></p>
          </div>
        `,
        width: '600px',
        showCloseButton: true,
        showConfirmButton: false
      });
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: 'Failed to Load Details',
        text: err.message
      });
    }
  };

  // Create PO for Supplier
  window.createPOForSupplier = async function(supplierId, supplierName) {
    // Open the PO creation modal with supplier pre-selected
    showCreatePOModalWithSupplier(supplierId, supplierName);
  };

  // View PO Details
  window.viewPODetails = async function(poId) {
    try {
      const response = await Api.get(`api/purchase_orders.php?id=${poId}`);
      const po = response.data;
      
      const statusColors = {
        'draft': 'secondary',
        'pending': 'warning',
        'approved': 'success',
        'received': 'info'
      };
      
      const statusColor = statusColors[po.status] || 'secondary';
      
      // Build line items table
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
      ` : '<p class="text-muted">No items added yet</p>';
      
      // Status change buttons
      const statusButtons = getStatusChangeButtons(po.id, po.status);
      
      Swal.fire({
        title: `Purchase Order ${po.po_number}`,
        html: `
          <div class="text-start">
            <div class="row mb-3">
              <div class="col-md-6">
                <p><strong>Supplier:</strong> ${po.supplier_name}</p>
                <p><strong>Warehouse:</strong> ${po.warehouse_name}</p>
                <p><strong>Order Date:</strong> ${po.order_date ? new Date(po.order_date).toLocaleDateString() : '-'}</p>
              </div>
              <div class="col-md-6">
                <p><strong>Expected Delivery:</strong> ${po.expected_delivery_date ? new Date(po.expected_delivery_date).toLocaleDateString() : '-'}</p>
                ${po.ai_predicted_delivery ? `<p class="text-info small"><i class="fa-solid fa-robot me-1"></i>AI Predicted: ${new Date(po.ai_predicted_delivery).toLocaleDateString()}</p>` : ''}
                <p><strong>Status:</strong> <span class="badge bg-${statusColor}">${po.status.toUpperCase()}</span></p>
              </div>
            </div>
            
            <div class="mb-3">
              <p><strong>Total Amount:</strong> <span class="h5 text-success">₱${parseFloat(po.total_amount).toFixed(2)}</span></p>
            </div>
            
            ${itemsHtml}
            
            ${po.notes ? `<div class="alert alert-secondary small mt-3"><strong>Notes:</strong> ${po.notes}</div>` : ''}
            
            ${po.ai_status_notes ? `<div class="alert alert-info small mt-2"><i class="fa-solid fa-robot me-1"></i><strong>AI Notes:</strong> ${po.ai_status_notes}</div>` : ''}
            
            <div class="mt-3">
              <h6>Status Actions:</h6>
              ${statusButtons}
            </div>
          </div>
        `,
        width: '800px',
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
  
  // Get status change buttons based on current status (USER PERSPECTIVE)
  function getStatusChangeButtons(poId, currentStatus) {
    // User can only: submit for approval, reject to draft, and mark as received
    const transitions = {
      'draft': [
        { status: 'pending_approval', label: 'Submit for Approval', color: 'warning', icon: 'paper-plane', userType: 'user' }
      ],
      'pending_approval': [
        { status: 'draft', label: 'Reject to Draft', color: 'secondary', icon: 'times', userType: 'user' }
      ],
      'approved': [
        // Supplier will mark as sent, user just waits
      ],
      'sent': [
        { status: 'received', label: 'Mark as Received', color: 'success', icon: 'box', userType: 'user' }
      ],
      'received': []
    };
    
    const buttons = transitions[currentStatus] || [];
    
    const statusMessages = {
      'pending_approval': '<p class="text-info small mb-2"><i class="fa-solid fa-clock me-1"></i>Waiting for supplier approval...</p>',
      'approved': '<p class="text-info small mb-2"><i class="fa-solid fa-clock me-1"></i>Waiting for supplier to ship the order...</p>',
      'received': '<p class="text-success small mb-2"><i class="fa-solid fa-check-circle me-1"></i>This order is complete.</p>'
    };

    let html = statusMessages[currentStatus] || '';

    if (buttons.length > 0) {
      html += buttons.map(btn => 
        `<button class="btn btn-sm btn-${btn.color}" onclick="changePOStatus(${poId}, '${btn.status}')">
          <i class="fa-solid fa-${btn.icon} me-1"></i>${btn.label}
        </button>`
      ).join(' ');
    }

    // Add archive button (only for draft or completed orders)
    if (currentStatus === 'draft' || currentStatus === 'received') {
      html += `<button class="btn btn-sm btn-outline-danger ms-2" onclick="archivePO(${poId})">
                <i class="fa-solid fa-archive me-1"></i>Archive
              </button>`;
    }

    return html || '<p class="text-muted small">No actions available.</p>';
  }

  // Archive PO
  window.archivePO = async function(poId) {
    const result = await Swal.fire({
      title: 'Archive Purchase Order?',
      text: 'This will move the PO to the archived list. You can still view it later.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#d33',
      cancelButtonColor: '#3085d6',
      confirmButtonText: 'Yes, archive it!'
    });

    if (result.isConfirmed) {
      try {
        await Api.send('api/archive_po.php', 'POST', { po_id: poId });
        Swal.fire('Archived!', 'The purchase order has been archived.', 'success');
        loadPurchaseOrders(); // Refresh the list
        const detailModal = bootstrap.Modal.getInstance(document.getElementById('po-detail-modal'));
        if (detailModal) {
          detailModal.hide();
        }
      } catch (err) {
        Swal.fire('Error', `Failed to archive PO: ${err.message}`, 'error');
      }
    }
  }
  
  // Change PO Status
  window.changePOStatus = async function(poId, newStatus, currentStatus) {
    const statusLabels = {
      'draft': 'Draft',
      'pending_approval': 'Pending Approval',
      'approved': 'Approved',
      'sent': 'Sent',
      'partially_received': 'Partially Received',
      'received': 'Received',
      'cancelled': 'Cancelled'
    };
    
    const currentLabel = currentStatus ? (statusLabels[currentStatus] || currentStatus.toUpperCase()) : 'Unknown';
    const newLabel = statusLabels[newStatus] || newStatus.toUpperCase();
    
    const result = await Swal.fire({
      title: 'Change PO Status?',
      html: `
        <p>Change status from <strong>${currentLabel}</strong> to <strong>${newLabel}</strong>?</p>
        <div class="text-start mt-3">
          <label class="form-label">Notes (optional):</label>
          <textarea id="status-change-notes" class="form-control" rows="3" placeholder="Add notes about this status change..."></textarea>
        </div>
      `,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: `Yes, Change to ${newLabel}`,
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#28a745',
      preConfirm: () => {
        return {
          notes: document.getElementById('status-change-notes').value.trim()
        };
      }
    });
    
    if (result.isConfirmed) {
      try {
        Swal.fire({
          title: 'Updating Status...',
          html: '<div class="spinner-border text-primary"></div><p class="mt-2"><i class="fa-solid fa-robot me-1"></i>AI is analyzing this change...</p>',
          showConfirmButton: false,
          allowOutsideClick: false
        });
        
        const response = await Api.send('api/po_status.php', 'POST', {
          po_id: poId,
          new_status: newStatus,
          notes: result.value.notes,
          user_type: 'user'
        });
        
        // Close detail modal first
        Swal.close();
        
        // Reload PO list
        await loadPurchaseOrders();
        
        // Show success message
        await Swal.fire({
          icon: 'success',
          title: 'Status Updated!',
          html: `
            <p>PO status changed to <strong>${newStatus.toUpperCase()}</strong></p>
            ${response.data.ai_notes ? `<div class="alert alert-info small mt-2"><i class="fa-solid fa-robot me-1"></i>${response.data.ai_notes}</div>` : ''}
            <p class="text-muted small">AI Confidence: ${(response.data.ai_confidence * 100).toFixed(0)}%</p>
          `,
          timer: 3000
        });
        
      } catch (err) {
        Swal.fire({
          icon: 'error',
          title: 'Status Change Failed',
          text: err.message
        });
      }
    }
  };

  // Approve Reorder
  window.approveReorder = async function(productId, productName, quantity) {
    const result = await Swal.fire({
      title: 'Approve Reorder?',
      html: `
        <p>Create purchase order for <strong>${productName}</strong>?</p>
        <p>Quantity: <strong>${quantity}</strong> units</p>
      `,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, Create PO',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#28a745'
    });
    
    if (result.isConfirmed) {
      try {
        // TODO: Implement PO creation from reorder
        await Swal.fire({
          icon: 'success',
          title: 'Reorder Approved',
          text: 'Purchase order will be created',
          timer: 2000
        });
        
        loadReorderAlerts();
      } catch (err) {
        Swal.fire({
          icon: 'error',
          title: 'Failed to Approve',
          text: err.message
        });
      }
    }
  };

  // Enhanced PO Modal with Line Items
  async function showEnhancedPOModal(supplierId, supplierName, products) {
    let lineItems = [];
    let totalAmount = 0;
    let modalInstance = null;
    
    const productOptions = products.map(p => 
      `<option value="${p.id}" data-price="${p.unit_price}">${p.name} (${p.sku}) - ₱${parseFloat(p.unit_price).toFixed(2)}</option>`
    ).join('');
    
    function renderLineItems() {
      if (lineItems.length === 0) {
        return '<div class="text-muted small">No items added yet. Click "Add Item" to start.</div>';
      }
      
      return `
        <table class="table table-sm">
          <thead>
            <tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th><th></th></tr>
          </thead>
          <tbody>
            ${lineItems.map((item, idx) => `
              <tr>
                <td><small>${item.product_name}</small></td>
                <td>${item.quantity}</td>
                <td>₱${parseFloat(item.unit_price).toFixed(2)}</td>
                <td><strong>₱${(item.quantity * item.unit_price).toFixed(2)}</strong></td>
                <td><button class="btn btn-sm btn-danger" data-remove-idx="${idx}"><i class="fa-solid fa-trash"></i></button></td>
              </tr>
            `).join('')}
          </tbody>
          <tfoot>
            <tr><td colspan="3" class="text-end"><strong>Total:</strong></td><td colspan="2"><strong>₱${totalAmount.toFixed(2)}</strong></td></tr>
          </tfoot>
        </table>
      `;
    }
    
    function updateModal() {
      const displayEl = document.getElementById('po-line-items-display');
      const totalEl = document.getElementById('po-total-amount');
      if (displayEl) displayEl.innerHTML = renderLineItems();
      if (totalEl) totalEl.textContent = `₱${totalAmount.toFixed(2)}`;
      
      // Re-attach remove button listeners
      document.querySelectorAll('[data-remove-idx]').forEach(btn => {
        btn.addEventListener('click', function() {
          const idx = parseInt(this.getAttribute('data-remove-idx'));
          lineItems.splice(idx, 1);
          totalAmount = lineItems.reduce((sum, item) => sum + (item.quantity * item.unit_price), 0);
          updateModal();
        });
      });
    }
    
    async function addLineItem() {
      // Store current modal state
      const currentModal = Swal.getPopup();
      
      const { value: itemData } = await Swal.fire({
        title: 'Add Line Item',
        html: `
          <div class="text-start">
            <div class="mb-3">
              <label class="form-label">Product <span class="text-danger">*</span></label>
              <select id="line-product" class="form-control">
                <option value="">Select product...</option>
                ${productOptions}
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Quantity <span class="text-danger">*</span></label>
              <input type="number" id="line-quantity" class="form-control" min="1" value="1">
            </div>
            <div class="mb-3">
              <label class="form-label">Unit Price <span class="text-danger">*</span></label>
              <input type="number" id="line-price" class="form-control" step="0.01" min="0">
            </div>
          </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Add Item',
        cancelButtonText: 'Cancel',
        didOpen: () => {
          document.getElementById('line-product').addEventListener('change', (e) => {
            const price = e.target.selectedOptions[0]?.dataset.price || 0;
            document.getElementById('line-price').value = price;
          });
        },
        preConfirm: () => {
          const productId = document.getElementById('line-product').value;
          const quantity = parseInt(document.getElementById('line-quantity').value);
          const price = parseFloat(document.getElementById('line-price').value);
          
          if (!productId) {
            Swal.showValidationMessage('Please select a product');
            return false;
          }
          if (!quantity || quantity < 1) {
            Swal.showValidationMessage('Quantity must be at least 1');
            return false;
          }
          if (!price || price < 0) {
            Swal.showValidationMessage('Price must be valid');
            return false;
          }
          
          const productName = document.getElementById('line-product').selectedOptions[0].text;
          return { product_id: parseInt(productId), product_name: productName, quantity, unit_price: price };
        }
      });
      
      if (itemData) {
        lineItems.push(itemData);
        totalAmount = lineItems.reduce((sum, item) => sum + (item.quantity * item.unit_price), 0);
        
        // Show success toast
        const Toast = Swal.mixin({
          toast: true,
          position: 'top-end',
          showConfirmButton: false,
          timer: 2000,
          timerProgressBar: true
        });
        
        await Toast.fire({
          icon: 'success',
          title: `Added ${itemData.product_name.split('(')[0].trim()}`
        });
        
        // Re-open the main modal with updated items
        showMainModal();
      } else {
        // User cancelled, re-open main modal
        showMainModal();
      }
    }
    
    function showMainModal() {
      Swal.fire({
        title: 'Create Purchase Order',
        html: `
          <div class="text-start">
            <div class="mb-3">
              <label class="form-label">Supplier</label>
              <div class="form-control bg-light"><strong>${supplierName}</strong></div>
            </div>
            
            <div class="mb-3">
              <label class="form-label">Order Date</label>
              <input type="date" id="po-order-date" class="form-control bg-light" value="${new Date().toISOString().split('T')[0]}" readonly>
              <small class="text-muted"><i class="fa-solid fa-robot me-1"></i>Auto-set to today</small>
            </div>
            
            <div class="mb-3">
              <label class="form-label">Line Items <span class="text-danger">*</span></label>
              <div id="po-line-items-display" class="border rounded p-2 mb-2" style="max-height: 200px; overflow-y: auto;">
                ${renderLineItems()}
              </div>
              <button type="button" class="btn btn-sm btn-success" id="add-line-item-btn">
                <i class="fa-solid fa-plus me-1"></i>Add Item
              </button>
            </div>
            
            <div class="mb-3">
              <label class="form-label">Total Amount</label>
              <div class="form-control bg-light"><strong id="po-total-amount">₱${totalAmount.toFixed(2)}</strong></div>
            </div>
            
            <div class="mb-3">
              <label class="form-label">Notes</label>
              <textarea id="po-notes" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
            </div>
          </div>
        `,
        width: '700px',
        showCancelButton: true,
        confirmButtonText: '<i class="fa-solid fa-file-invoice me-2"></i>Create PO',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#0d6efd',
        didOpen: () => {
          const addBtn = document.getElementById('add-line-item-btn');
          if (addBtn) {
            addBtn.addEventListener('click', addLineItem);
          }
          updateModal();
        },
        preConfirm: async () => {
          if (lineItems.length === 0) {
            Swal.showValidationMessage('Please add at least one line item');
            return false;
          }
          
          Swal.showLoading();
          const aiDeliveryDate = await getAIPredictedDelivery();
          
          return {
            supplier_id: parseInt(supplierId),
            order_date: document.getElementById('po-order-date').value,
            expected_delivery: aiDeliveryDate,
            notes: document.getElementById('po-notes').value.trim(),
            items: lineItems,
            total_amount: totalAmount,
            use_ai_prediction: true
          };
        }
      }).then(async (result) => {
        if (result.isConfirmed && result.value) {
          await createPO(result.value);
        }
      });
    }
    
    // Get AI predicted delivery date
    async function getAIPredictedDelivery() {
      try {
        const response = await Api.send('api/ai.php', 'POST', {
          action: 'predict_delivery',
          supplier_id: supplierId,
          items: lineItems
        });
        return response.data.predicted_date || null;
      } catch (err) {
        console.error('AI delivery prediction failed:', err);
        // Fallback: predict 7 days from now
        const futureDate = new Date();
        futureDate.setDate(futureDate.getDate() + 7);
        return futureDate.toISOString().split('T')[0];
      }
    }
    
    // Create PO function
    async function createPO(formValues) {
      try {
        Swal.fire({
          title: 'Creating Purchase Order...',
          html: '<div class="spinner-border text-primary"></div>',
          showConfirmButton: false,
          allowOutsideClick: false
        });
        
        const response = await Api.send('api/purchase_orders.php', 'POST', formValues);
        
        await Swal.fire({
          icon: 'success',
          title: 'Purchase Order Created!',
          html: `
            <p>PO <strong>${response.data.po_number}</strong> created for <strong>${supplierName}</strong></p>
            <p class="text-muted small">Total: ₱${totalAmount.toFixed(2)} | ${lineItems.length} items</p>
            ${formValues.expected_delivery ? `<p class="text-info small"><i class="fa-solid fa-robot me-1"></i>AI Predicted Delivery: ${formValues.expected_delivery}</p>` : ''}
          `,
          timer: 4000,
          showConfirmButton: true
        });
        
        document.getElementById('purchase-orders-tab').click();
        setTimeout(() => loadPurchaseOrders(), 100);
        
        if (typeof loadNotifications === 'function') {
          setTimeout(loadNotifications, 500);
        }
      } catch (err) {
        Swal.fire({
          icon: 'error',
          title: 'Failed to Create PO',
          text: err.message
        });
      }
    }
    
    // Start by showing the main modal
    showMainModal();
  }


  // Show Add Supplier Modal
  async function showAddSupplierModal() {
    const { value: formValues } = await Swal.fire({
      title: 'Add New Supplier',
      html: `
        <div class="text-start">
          <div class="mb-3">
            <label class="form-label">Supplier Name <span class="text-danger">*</span></label>
            <input type="text" id="supplier-name" class="form-control" placeholder="e.g., ABC Corporation">
          </div>
          
          <div class="mb-3">
            <label class="form-label">Supplier Code <span class="text-danger">*</span></label>
            <input type="text" id="supplier-code" class="form-control" placeholder="e.g., SUP001">
            <small class="text-muted">Unique identifier for this supplier</small>
          </div>
          
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Contact Person</label>
              <input type="text" id="supplier-contact" class="form-control" placeholder="e.g., John Doe">
            </div>
            
            <div class="col-md-6 mb-3">
              <label class="form-label">Phone</label>
              <input type="tel" id="supplier-phone" class="form-control" placeholder="e.g., +63-123-4567">
            </div>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" id="supplier-email" class="form-control" placeholder="e.g., contact@supplier.com">
          </div>
          
          <div class="mb-3">
            <label class="form-label">Address</label>
            <textarea id="supplier-address" class="form-control" rows="2" placeholder="Street address"></textarea>
          </div>
          
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">City</label>
              <input type="text" id="supplier-city" class="form-control" placeholder="e.g., Manila">
            </div>
            
            <div class="col-md-6 mb-3">
              <label class="form-label">Country</label>
              <input type="text" id="supplier-country" class="form-control" placeholder="e.g., Philippines" value="Philippines">
            </div>
          </div>
          
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="supplier-active" checked>
            <label class="form-check-label" for="supplier-active">
              Active Supplier
            </label>
          </div>
        </div>
      `,
      width: '700px',
      showCancelButton: true,
      confirmButtonText: '<i class="fa-solid fa-save me-2"></i>Save Supplier',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#0d6efd',
      preConfirm: () => {
        const name = document.getElementById('supplier-name').value.trim();
        const code = document.getElementById('supplier-code').value.trim();
        
        if (!name) {
          Swal.showValidationMessage('Supplier name is required');
          return false;
        }
        
        if (!code) {
          Swal.showValidationMessage('Supplier code is required');
          return false;
        }
        
        return {
          name: name,
          code: code,
          contact_person: document.getElementById('supplier-contact').value.trim(),
          phone: document.getElementById('supplier-phone').value.trim(),
          email: document.getElementById('supplier-email').value.trim(),
          address: document.getElementById('supplier-address').value.trim(),
          city: document.getElementById('supplier-city').value.trim(),
          country: document.getElementById('supplier-country').value.trim(),
          is_active: document.getElementById('supplier-active').checked ? 1 : 0
        };
      }
    });
    
    if (formValues) {
      try {
        // Show loading
        Swal.fire({
          title: 'Creating Supplier...',
          html: '<div class="spinner-border text-primary"></div>',
          showConfirmButton: false,
          allowOutsideClick: false
        });
        
        const response = await Api.send('api/suppliers.php', 'POST', formValues);
        
        await Swal.fire({
          icon: 'success',
          title: 'Supplier Added!',
          html: `
            <p><strong>${formValues.name}</strong> has been added to your supplier directory.</p>
            <p class="text-muted small">Supplier Code: <code>${formValues.code}</code></p>
          `,
          timer: 3000,
          showConfirmButton: true
        });
        
        // Reload suppliers list
        loadSuppliers();
        
        // Refresh notifications
        if (typeof loadNotifications === 'function') {
          setTimeout(loadNotifications, 500);
        }
        
      } catch (err) {
        Swal.fire({
          icon: 'error',
          title: 'Failed to Add Supplier',
          text: err.message
        });
      }
    }
  }

  // Show Create PO Modal with pre-selected supplier
  async function showCreatePOModalWithSupplier(preSelectedSupplierId, preSelectedSupplierName) {
    // Load products for line items
    const productsResponse = await Api.get('api/products.php?limit=1000');
    const products = productsResponse.data.items || [];
    
    // Open enhanced PO creation modal
    await showEnhancedPOModal(preSelectedSupplierId, preSelectedSupplierName, products);
  }

  // Show Create PO Modal
  async function showCreatePOModal() {
    // First, load suppliers and products
    try {
      const [suppliersResponse, productsResponse] = await Promise.all([
        Api.get('api/suppliers.php?limit=100'),
        Api.get('api/products.php?limit=1000')
      ]);
      
      const suppliers = suppliersResponse.data.items || [];
      const products = productsResponse.data.items || [];
      
      if (suppliers.length === 0) {
        Swal.fire({
          icon: 'warning',
          title: 'No Suppliers',
          text: 'Please add suppliers first before creating a purchase order'
        });
        return;
      }
      
      const supplierOptions = suppliers
        .filter(s => s.is_active)
        .map(s => `<option value="${s.id}" data-name="${s.name}">${s.name} (${s.code})</option>`)
        .join('');
      
      const { value: supplierSelection } = await Swal.fire({
        title: 'Select Supplier',
        html: `
          <div class="text-start">
            <div class="mb-3">
              <label class="form-label">Supplier <span class="text-danger">*</span></label>
              <select id="po-supplier" class="form-control">
                <option value="">Select supplier...</option>
                ${supplierOptions}
              </select>
            </div>
          </div>
        `,
        width: '500px',
        showCancelButton: true,
        confirmButtonText: 'Continue',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#0d6efd',
        preConfirm: () => {
          const supplierId = document.getElementById('po-supplier').value;
          const supplierName = document.getElementById('po-supplier').selectedOptions[0]?.dataset.name;
          
          if (!supplierId) {
            Swal.showValidationMessage('Please select a supplier');
            return false;
          }
          
          return { supplier_id: parseInt(supplierId), supplier_name: supplierName };
        }
      });
      
      if (supplierSelection) {
        // Open enhanced modal with selected supplier
        await showEnhancedPOModal(supplierSelection.supplier_id, supplierSelection.supplier_name, products);
      }
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: 'Failed to Load Data',
        text: err.message
      });
    }
  }

  // Event Listeners
  document.addEventListener('DOMContentLoaded', () => {
    // Load initial data
    loadSuppliers();
    
    // Supplier search
    let supplierSearchTimeout;
    document.getElementById('supplier-search').addEventListener('input', (e) => {
      clearTimeout(supplierSearchTimeout);
      supplierSearchTimeout = setTimeout(() => {
        loadSuppliers(e.target.value);
      }, 300);
    });
    
    // PO filter buttons
    document.querySelectorAll('input[name="po-filter"]').forEach(radio => {
      radio.addEventListener('change', (e) => {
        loadPurchaseOrders(e.target.value);
      });
    });
    
    // Tab change events
    document.getElementById('purchase-orders-tab').addEventListener('shown.bs.tab', () => {
      loadPurchaseOrders();
    });
    
    // PO filter buttons
    document.querySelectorAll('input[name="po-filter"]').forEach(radio => {
      radio.addEventListener('change', (e) => {
        const status = e.target.value;
        loadPurchaseOrders(status);
      });
    });
    
    document.getElementById('reorder-tab').addEventListener('shown.bs.tab', () => {
      loadReorderAlerts();
    });
    
    // Add supplier button
    document.getElementById('add-supplier-btn').addEventListener('click', showAddSupplierModal);
    
    // Create PO button
    document.getElementById('create-po-btn').addEventListener('click', showCreatePOModal);
    
    // Approve all button
    document.getElementById('approve-all-btn').addEventListener('click', () => {
      Swal.fire({
        icon: 'info',
        title: 'Approve All Reorders',
        text: 'Bulk approval feature coming soon'
      });
    });
  });

})();
