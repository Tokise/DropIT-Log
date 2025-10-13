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
      console.log('loadPurchaseOrders called with status:', status, 'page:', page);
      currentPOPage = page;
      const timestamp = new Date().getTime(); // Cache buster
      const url = status 
        ? `api/purchase_orders.php?status=${status}&page=${page}&limit=${itemsPerPage}&_=${timestamp}`
        : `api/purchase_orders.php?page=${page}&limit=${itemsPerPage}&_=${timestamp}`;
      
      console.log('Calling API URL:', url);
      const response = await Api.get(url);
      console.log('Raw API Response:', response);
      const container = document.getElementById('purchase-orders-list');
      
      // Debug: log what we received
      console.log('PO API Response data:', response.data);
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
    let subtotal = 0;
    let taxRate = 0; // Will be loaded from supplier settings
    let taxAmount = 0;
    let totalAmount = 0;
    let modalInstance = null;
    let supplierChargesTax = false;
    
    // Load supplier tax settings
    try {
      const supplierResponse = await Api.get(`api/suppliers.php?id=${supplierId}`);
      const supplier = supplierResponse.data;
      supplierChargesTax = supplier.charges_tax == 1;
      taxRate = supplierChargesTax ? parseFloat(supplier.tax_rate || 0.12) : 0;
      console.log(`Supplier ${supplierName} - Charges Tax: ${supplierChargesTax}, Rate: ${taxRate}`);
    } catch (err) {
      console.error('Failed to load supplier tax settings:', err);
      // Default to 12% VAT if unable to load
      supplierChargesTax = true;
      taxRate = 0.12;
    }
    
    const productOptions = products.map(p => 
      `<option value="${p.id}" data-price="${p.unit_price}" data-name="${p.product_name}" data-code="${p.product_code}">${p.product_name} (${p.product_code}) - ₱${parseFloat(p.unit_price).toFixed(2)}</option>`
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
                <td><small>${item.product_name}</small><br><code class="text-muted" style="font-size: 0.7em;">${item.product_code || ''}</code></td>
                <td>${item.quantity}</td>
                <td>₱${parseFloat(item.unit_price).toFixed(2)}</td>
                <td><strong>₱${(item.quantity * item.unit_price).toFixed(2)}</strong></td>
                <td><button class="btn btn-sm btn-danger" data-remove-idx="${idx}"><i class="fa-solid fa-trash"></i></button></td>
              </tr>
            `).join('')}
          </tbody>
          <tfoot>
            <tr><td colspan="3" class="text-end">Subtotal:</td><td colspan="2">₱${subtotal.toFixed(2)}</td></tr>
            ${supplierChargesTax ? `<tr><td colspan="3" class="text-end">Tax (${(taxRate * 100).toFixed(0)}% VAT):</td><td colspan="2">₱${taxAmount.toFixed(2)}</td></tr>` : '<tr><td colspan="3" class="text-end text-muted"><em>Tax Exempt</em></td><td colspan="2">₱0.00</td></tr>'}
            <tr class="table-primary"><td colspan="3" class="text-end"><strong>Total Amount:</strong></td><td colspan="2"><strong>₱${totalAmount.toFixed(2)}</strong></td></tr>
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
          // Recalculate totals
          subtotal = lineItems.reduce((sum, item) => sum + (item.quantity * item.unit_price), 0);
          taxAmount = subtotal * taxRate;
          totalAmount = subtotal + taxAmount;
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
          
          const selectedOption = document.getElementById('line-product').selectedOptions[0];
          const productName = selectedOption.dataset.name || selectedOption.text;
          const productCode = selectedOption.dataset.code || '';
          return { 
            supplier_product_id: parseInt(productId), 
            product_name: productName,
            product_code: productCode,
            quantity, 
            unit_price: price 
          };
        }
      });
      
      if (itemData) {
        lineItems.push(itemData);
        // Recalculate totals with tax
        subtotal = lineItems.reduce((sum, item) => sum + (item.quantity * item.unit_price), 0);
        taxAmount = subtotal * taxRate;
        totalAmount = subtotal + taxAmount;
        
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
              <div class="card">
                <div class="card-body">
                  <div class="d-flex justify-content-between mb-1">
                    <span>Subtotal:</span>
                    <span>₱${subtotal.toFixed(2)}</span>
                  </div>
                  <div class="d-flex justify-content-between mb-2">
                    ${supplierChargesTax ? `<span>Tax (${(taxRate * 100).toFixed(0)}% VAT):</span><span>₱${taxAmount.toFixed(2)}</span>` : '<span class="text-muted"><em>Tax Exempt</em></span><span>₱0.00</span>'}
                  </div>
                  <hr class="my-2">
                  <div class="d-flex justify-content-between">
                    <strong>Total Amount:</strong>
                    <strong class="text-primary" id="po-total-amount">₱${totalAmount.toFixed(2)}</strong>
                  </div>
                </div>
              </div>
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
            subtotal: subtotal,
            tax_rate: taxRate,
            tax_amount: taxAmount,
            total_amount: totalAmount,
            is_tax_inclusive: supplierChargesTax ? 1 : 0,
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
    // Load supplier-specific products from their catalog
    const productsResponse = await Api.get(`api/supplier_products.php?supplier_id=${preSelectedSupplierId}`);
    const products = productsResponse.data.items || [];
    
    if (products.length === 0) {
      Swal.fire({
        icon: 'warning',
        title: 'No Products Available',
        text: `${preSelectedSupplierName} has no products in their catalog. Please add products to this supplier first.`
      });
      return;
    }
    
    // Open enhanced PO creation modal
    await showEnhancedPOModal(preSelectedSupplierId, preSelectedSupplierName, products);
  }

  // Show Create PO Modal
  async function showCreatePOModal() {
    // First, load suppliers only (products will be loaded after supplier selection)
    try {
      const suppliersResponse = await Api.get('api/suppliers.php?limit=100');
      
      const suppliers = suppliersResponse.data.items || [];
      
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
        // Load supplier-specific products
        const productsResponse = await Api.get(`api/supplier_products.php?supplier_id=${supplierSelection.supplier_id}`);
        const products = productsResponse.data.items || [];
        
        if (products.length === 0) {
          Swal.fire({
            icon: 'warning',
            title: 'No Products Available',
            text: `${supplierSelection.supplier_name} has no products in their catalog. Please add products to this supplier first.`
          });
          return;
        }
        
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
    
    // Load purchase orders if we're on the PO tab
    const hash = window.location.hash.substring(1);
    const params = new URLSearchParams(hash);
    const currentTab = params.get('tab');
    
    if (currentTab === 'purchase-orders') {
      const filter = params.get('filter') || 'all';
      loadPurchaseOrders(filter);
    } else if (currentTab === 'performance') {
      loadSupplierPerformance();
    }
    
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
        window.location.hash = `tab=purchase-orders&filter=${e.target.value}`;
      });
    });
    
    // Tab change handlers
    document.getElementById('suppliers-tab').addEventListener('shown.bs.tab', () => {
      window.location.hash = 'tab=suppliers';
      loadSuppliers();
    });
    
    document.getElementById('purchase-orders-tab').addEventListener('shown.bs.tab', () => {
      const hash = window.location.hash.substring(1);
      const params = new URLSearchParams(hash);
      const filter = params.get('filter') || '';
      window.location.hash = `tab=purchase-orders&filter=${filter}`;
      loadPurchaseOrders(filter);
    });
    
    document.getElementById('reorder-tab').addEventListener('shown.bs.tab', () => {
      window.location.hash = 'tab=reorder';
      loadReorderAlerts();
    });
    
    // Supplier products tab
    document.getElementById('supplier-products-tab').addEventListener('shown.bs.tab', () => {
      // Check if there's a page in the URL hash
      const hash = window.location.hash.substring(1);
      const params = new URLSearchParams(hash);
      const page = parseInt(params.get('page')) || 1;
      const supplier = params.get('supplier') || '';
      const search = params.get('search') || '';
      
      loadSupplierProducts(supplier, page, search);
      loadSupplierFilterOptions();
      
      // Set the search and filter inputs
      if (search) document.getElementById('supplier-product-search').value = search;
      if (supplier) document.getElementById('supplier-product-filter').value = supplier;
    });
    
    // Add supplier button
    document.getElementById('add-supplier-btn').addEventListener('click', showAddSupplierModal);
    
    // Create PO button
    document.getElementById('create-po-btn').addEventListener('click', showCreatePOModal);
    
    // Add supplier product button
    document.getElementById('add-supplier-product-btn').addEventListener('click', showAddSupplierProductModal);
    
    // Supplier product filter
    document.getElementById('supplier-product-filter').addEventListener('change', (e) => {
      loadSupplierProducts(e.target.value, 1, currentProductsSearch);
    });
    
    // Supplier product search
    let productSearchTimeout;
    document.getElementById('supplier-product-search').addEventListener('input', (e) => {
      clearTimeout(productSearchTimeout);
      productSearchTimeout = setTimeout(() => {
        loadSupplierProducts(currentProductsSupplier, 1, e.target.value);
      }, 300);
    });
    
    // Approve all button
    document.getElementById('approve-all-btn').addEventListener('click', () => {
      Swal.fire({
        icon: 'info',
        title: 'Approve All Reorders',
        text: 'Bulk approval feature coming soon'
      });
    });
  });

  // ============================================
  // SUPPLIER PRODUCTS MANAGEMENT
  // ============================================

  // Supplier Products Pagination State
  let currentProductsPage = 1;
  let productsPerPage = 5;
  let currentProductsSearch = '';
  let currentProductsSupplier = '';

  // Load Supplier Products with Pagination
  window.loadSupplierProducts = async function(supplierId = '', page = 1, search = '') {
    try {
      // Ensure page is a number
      page = parseInt(page) || 1;
      
      currentProductsPage = page;
      currentProductsSearch = search;
      currentProductsSupplier = supplierId;
      
      // Update URL hash with current page
      const hashParams = new URLSearchParams();
      hashParams.set('tab', 'supplier-products');
      hashParams.set('page', page);
      if (supplierId) hashParams.set('supplier', supplierId);
      if (search) hashParams.set('search', search);
      window.location.hash = hashParams.toString();
      
      console.log('Loading products - Page:', page, 'Supplier:', supplierId, 'Search:', search);
      
      const container = document.getElementById('supplier-products-list');
      container.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2">Loading products...</p></div>';
      
      // Scroll to top of the products list
      container.scrollIntoView({ behavior: 'smooth', block: 'start' });
      
      const url = supplierId 
        ? `api/supplier_products.php?supplier_id=${supplierId}`
        : 'api/supplier_products.php';
      
      console.log('Loading supplier products from:', url);
      const response = await Api.get(url);
      console.log('Supplier products response:', response);
      
      if (!response.data || !response.data.items || response.data.items.length === 0) {
        container.innerHTML = '<div class="alert alert-info">No supplier products found. Add products to suppliers to enable purchase orders.</div>';
        return;
      }
      
      let products = response.data.items;
      
      // Apply search filter
      if (search) {
        const searchLower = search.toLowerCase();
        products = products.filter(p => 
          p.product_name.toLowerCase().includes(searchLower) ||
          p.product_code.toLowerCase().includes(searchLower) ||
          p.supplier_name.toLowerCase().includes(searchLower) ||
          (p.category && p.category.toLowerCase().includes(searchLower))
        );
      }
      
      // Calculate pagination
      const totalProducts = products.length;
      const totalPages = Math.ceil(totalProducts / productsPerPage);
      const startIndex = (page - 1) * productsPerPage;
      const endIndex = startIndex + productsPerPage;
      const paginatedProducts = products.slice(startIndex, endIndex);
      
      console.log('Pagination:', {
        totalProducts,
        totalPages,
        currentPage: page,
        perPage: productsPerPage,
        startIndex,
        endIndex,
        showing: paginatedProducts.length
      });
      
      if (paginatedProducts.length === 0) {
        container.innerHTML = '<div class="alert alert-info">No products match your search.</div>';
        return;
      }
      
      const rows = paginatedProducts.map(product => `
        <tr>
          <td><strong>${product.product_name}</strong><br><small class="text-muted">${product.product_code}</small></td>
          <td>${product.supplier_name}</td>
          <td>${product.category || '-'}</td>
          <td>${product.unit_of_measure || 'pcs'}</td>
          <td><strong>₱${parseFloat(product.unit_price).toFixed(2)}</strong></td>
          <td>${product.minimum_order_qty || 1}</td>
          <td>${product.lead_time_days || 7} days</td>
          <td><span class="badge ${product.is_active ? 'bg-success' : 'bg-secondary'}">${product.is_active ? 'Active' : 'Inactive'}</span></td>
          <td>
            <button class="btn btn-sm btn-outline-primary" onclick="editSupplierProduct(${product.id})" title="Edit">
              <i class="fa-solid fa-edit"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteSupplierProduct(${product.id}, '${product.product_name.replace(/'/g, "\\'")}')">
              <i class="fa-solid fa-trash"></i>
            </button>
          </td>
        </tr>
      `).join('');
      
      // Generate pagination controls
      const paginationHtml = generateProductsPagination(page, totalPages, totalProducts);
      
      container.innerHTML = `
        <div class="table-responsive">
          <table class="table table-sm table-hover">
            <thead>
              <tr>
                <th>Product</th>
                <th>Supplier</th>
                <th>Category</th>
                <th>Unit</th>
                <th>Price</th>
                <th>Min Order</th>
                <th>Lead Time</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        ${paginationHtml}
      `;
    } catch (err) {
      console.error('Error loading supplier products:', err);
      const container = document.getElementById('supplier-products-list');
      if (container) {
        container.innerHTML = `<div class="alert alert-danger">Failed to load supplier products: ${err.message}</div>`;
      }
    }
  };

  // Generate Pagination HTML for Products
  function generateProductsPagination(currentPage, totalPages, totalItems) {
    if (totalPages <= 1) {
      return `<div class="text-muted small text-center mt-2">Showing ${totalItems} products</div>`;
    }
    
    const startItem = ((currentPage - 1) * productsPerPage) + 1;
    const endItem = Math.min(currentPage * productsPerPage, totalItems);
    
    let paginationButtons = '';
    
    // Previous button
    paginationButtons += `
      <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="event.preventDefault(); loadSupplierProducts('${currentProductsSupplier}', ${currentPage - 1}, '${currentProductsSearch}');">
          <i class="fa-solid fa-chevron-left"></i>
        </a>
      </li>
    `;
    
    // Page numbers
    const maxVisiblePages = 5;
    let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
    let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
    
    if (endPage - startPage < maxVisiblePages - 1) {
      startPage = Math.max(1, endPage - maxVisiblePages + 1);
    }
    
    if (startPage > 1) {
      paginationButtons += `
        <li class="page-item">
          <a class="page-link" href="#" onclick="event.preventDefault(); loadSupplierProducts('${currentProductsSupplier}', 1, '${currentProductsSearch}');">1</a>
        </li>
      `;
      if (startPage > 2) {
        paginationButtons += '<li class="page-item disabled"><span class="page-link">...</span></li>';
      }
    }
    
    for (let i = startPage; i <= endPage; i++) {
      paginationButtons += `
        <li class="page-item ${i === currentPage ? 'active' : ''}">
          <a class="page-link" href="#" onclick="event.preventDefault(); loadSupplierProducts('${currentProductsSupplier}', ${i}, '${currentProductsSearch}');">${i}</a>
        </li>
      `;
    }
    
    if (endPage < totalPages) {
      if (endPage < totalPages - 1) {
        paginationButtons += '<li class="page-item disabled"><span class="page-link">...</span></li>';
      }
      paginationButtons += `
        <li class="page-item">
          <a class="page-link" href="#" onclick="event.preventDefault(); loadSupplierProducts('${currentProductsSupplier}', ${totalPages}, '${currentProductsSearch}');">${totalPages}</a>
        </li>
      `;
    }
    
    // Next button
    paginationButtons += `
      <li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="event.preventDefault(); loadSupplierProducts('${currentProductsSupplier}', ${currentPage + 1}, '${currentProductsSearch}');">
          <i class="fa-solid fa-chevron-right"></i>
        </a>
      </li>
    `;
    
    return `
      <div class="d-flex justify-content-between align-items-center mt-3">
        <div class="text-muted small">
          Showing ${startItem}-${endItem} of ${totalItems} products
        </div>
        <nav>
          <ul class="pagination pagination-sm mb-0">
            ${paginationButtons}
          </ul>
        </nav>
      </div>
    `;
  }

  // Load supplier filter options
  async function loadSupplierFilterOptions() {
    try {
      const response = await Api.get('api/suppliers.php?limit=100');
      const suppliers = response.data.items || [];
      
      const options = suppliers
        .filter(s => s.is_active)
        .map(s => `<option value="${s.id}">${s.name} (${s.code})</option>`)
        .join('');
      
      const filterSelect = document.getElementById('supplier-product-filter');
      filterSelect.innerHTML = '<option value="">All Suppliers</option>' + options;
    } catch (err) {
      console.error('Failed to load supplier filter options:', err);
    }
  }

  // Show Add Supplier Product Modal
  async function showAddSupplierProductModal() {
    try {
      // Load suppliers
      const suppliersResponse = await Api.get('api/suppliers.php?limit=100');
      const suppliers = suppliersResponse.data.items || [];
      
      if (suppliers.length === 0) {
        Swal.fire({
          icon: 'warning',
          title: 'No Suppliers',
          text: 'Please add suppliers first before adding products'
        });
        return;
      }
      
      const supplierOptions = suppliers
        .filter(s => s.is_active)
        .map(s => `<option value="${s.id}">${s.name} (${s.code})</option>`)
        .join('');
      
      const { value: formValues } = await Swal.fire({
        title: 'Add Supplier Product',
        html: `
          <div class="text-start">
            <div class="mb-3">
              <label class="form-label">Supplier <span class="text-danger">*</span></label>
              <select id="sp-supplier" class="form-control">
                <option value="">Select supplier...</option>
                ${supplierOptions}
              </select>
            </div>
            
            <div class="mb-3">
              <label class="form-label">Product Name <span class="text-danger">*</span></label>
              <input type="text" id="sp-name" class="form-control" placeholder="e.g., Office Chair Executive">
            </div>
            
            <div class="mb-3">
              <label class="form-label">Product Code <span class="text-danger">*</span></label>
              <input type="text" id="sp-code" class="form-control" placeholder="e.g., ABC-CHAIR-001">
            </div>
            
            <div class="mb-3">
              <label class="form-label">Description</label>
              <textarea id="sp-description" class="form-control" rows="2" placeholder="Product description"></textarea>
            </div>
            
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Category</label>
                <input type="text" id="sp-category" class="form-control" placeholder="e.g., Furniture">
              </div>
              
              <div class="col-md-6 mb-3">
                <label class="form-label">Unit of Measure</label>
                <input type="text" id="sp-uom" class="form-control" placeholder="e.g., pcs" value="pcs">
              </div>
            </div>
            
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Unit Price (₱) <span class="text-danger">*</span></label>
                <input type="number" id="sp-price" class="form-control" step="0.01" min="0" placeholder="0.00">
              </div>
              
              <div class="col-md-6 mb-3">
                <label class="form-label">Currency</label>
                <input type="text" id="sp-currency" class="form-control" value="PHP" readonly>
              </div>
            </div>
            
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Minimum Order Qty</label>
                <input type="number" id="sp-min-qty" class="form-control" min="1" value="1">
              </div>
              
              <div class="col-md-6 mb-3">
                <label class="form-label">Lead Time (days)</label>
                <input type="number" id="sp-lead-time" class="form-control" min="1" value="7">
              </div>
            </div>
            
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" id="sp-active" checked>
              <label class="form-check-label" for="sp-active">
                Active Product
              </label>
            </div>
          </div>
        `,
        width: '700px',
        showCancelButton: true,
        confirmButtonText: '<i class="fa-solid fa-save me-2"></i>Save Product',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#0d6efd',
        preConfirm: () => {
          const supplierId = document.getElementById('sp-supplier').value;
          const name = document.getElementById('sp-name').value.trim();
          const code = document.getElementById('sp-code').value.trim();
          const price = document.getElementById('sp-price').value;
          
          if (!supplierId) {
            Swal.showValidationMessage('Please select a supplier');
            return false;
          }
          if (!name) {
            Swal.showValidationMessage('Product name is required');
            return false;
          }
          if (!code) {
            Swal.showValidationMessage('Product code is required');
            return false;
          }
          if (!price || parseFloat(price) < 0) {
            Swal.showValidationMessage('Valid unit price is required');
            return false;
          }
          
          return {
            supplier_id: parseInt(supplierId),
            product_name: name,
            product_code: code,
            description: document.getElementById('sp-description').value.trim(),
            category: document.getElementById('sp-category').value.trim(),
            unit_of_measure: document.getElementById('sp-uom').value.trim() || 'pcs',
            unit_price: parseFloat(price),
            currency: 'PHP',
            minimum_order_qty: parseInt(document.getElementById('sp-min-qty').value) || 1,
            lead_time_days: parseInt(document.getElementById('sp-lead-time').value) || 7,
            is_active: document.getElementById('sp-active').checked ? 1 : 0
          };
        }
      });
      
      if (formValues) {
        Swal.fire({
          title: 'Creating Product...',
          html: '<div class="spinner-border text-primary"></div>',
          showConfirmButton: false,
          allowOutsideClick: false
        });
        
        await Api.send('api/supplier_products.php', 'POST', formValues);
        
        await Swal.fire({
          icon: 'success',
          title: 'Product Added!',
          html: `<p><strong>${formValues.product_name}</strong> has been added to the supplier catalog.</p>`,
          timer: 3000
        });
        
        loadSupplierProducts();
      }
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: 'Failed to Add Product',
        text: err.message
      });
    }
  }

  // Edit Supplier Product
  window.editSupplierProduct = async function(productId) {
    try {
      // Load product details
      const response = await Api.get(`api/supplier_products.php?id=${productId}`);
      const product = response.data;
      
      const { value: formValues } = await Swal.fire({
        title: 'Edit Supplier Product',
        html: `
          <div class="text-start">
            <div class="mb-3">
              <label class="form-label">Supplier</label>
              <div class="form-control bg-light"><strong>${product.supplier_name}</strong></div>
            </div>
            
            <div class="mb-3">
              <label class="form-label">Product Name <span class="text-danger">*</span></label>
              <input type="text" id="sp-name" class="form-control" value="${product.product_name}">
            </div>
            
            <div class="mb-3">
              <label class="form-label">Product Code <span class="text-danger">*</span></label>
              <input type="text" id="sp-code" class="form-control" value="${product.product_code}">
            </div>
            
            <div class="mb-3">
              <label class="form-label">Description</label>
              <textarea id="sp-description" class="form-control" rows="2">${product.description || ''}</textarea>
            </div>
            
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Category</label>
                <input type="text" id="sp-category" class="form-control" value="${product.category || ''}">
              </div>
              
              <div class="col-md-6 mb-3">
                <label class="form-label">Unit of Measure</label>
                <input type="text" id="sp-uom" class="form-control" value="${product.unit_of_measure || 'pcs'}">
              </div>
            </div>
            
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Unit Price (₱) <span class="text-danger">*</span></label>
                <input type="number" id="sp-price" class="form-control" step="0.01" min="0" value="${product.unit_price}">
              </div>
              
              <div class="col-md-6 mb-3">
                <label class="form-label">Currency</label>
                <input type="text" id="sp-currency" class="form-control" value="${product.currency || 'PHP'}" readonly>
              </div>
            </div>
            
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Minimum Order Qty</label>
                <input type="number" id="sp-min-qty" class="form-control" min="1" value="${product.minimum_order_qty || 1}">
              </div>
              
              <div class="col-md-6 mb-3">
                <label class="form-label">Lead Time (days)</label>
                <input type="number" id="sp-lead-time" class="form-control" min="1" value="${product.lead_time_days || 7}">
              </div>
            </div>
            
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" id="sp-active" ${product.is_active ? 'checked' : ''}>
              <label class="form-check-label" for="sp-active">
                Active Product
              </label>
            </div>
          </div>
        `,
        width: '700px',
        showCancelButton: true,
        confirmButtonText: '<i class="fa-solid fa-save me-2"></i>Update Product',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#0d6efd',
        preConfirm: () => {
          const name = document.getElementById('sp-name').value.trim();
          const code = document.getElementById('sp-code').value.trim();
          const price = document.getElementById('sp-price').value;
          
          if (!name || !code || !price || parseFloat(price) < 0) {
            Swal.showValidationMessage('Please fill all required fields');
            return false;
          }
          
          return {
            id: productId,
            product_name: name,
            product_code: code,
            description: document.getElementById('sp-description').value.trim(),
            category: document.getElementById('sp-category').value.trim(),
            unit_of_measure: document.getElementById('sp-uom').value.trim() || 'pcs',
            unit_price: parseFloat(price),
            minimum_order_qty: parseInt(document.getElementById('sp-min-qty').value) || 1,
            lead_time_days: parseInt(document.getElementById('sp-lead-time').value) || 7,
            is_active: document.getElementById('sp-active').checked ? 1 : 0
          };
        }
      });
      
      if (formValues) {
        Swal.fire({
          title: 'Updating Product...',
          html: '<div class="spinner-border text-primary"></div>',
          showConfirmButton: false,
          allowOutsideClick: false
        });
        
        await Api.send('api/supplier_products.php', 'PUT', formValues);
        
        await Swal.fire({
          icon: 'success',
          title: 'Product Updated!',
          timer: 2000
        });
        
        loadSupplierProducts();
      }
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: 'Failed to Update Product',
        text: err.message
      });
    }
  };

  // Delete Supplier Product
  window.deleteSupplierProduct = async function(productId, productName) {
    const result = await Swal.fire({
      title: 'Delete Product?',
      html: `Are you sure you want to delete <strong>${productName}</strong>?<br><small class="text-muted">This will deactivate the product.</small>`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#d33',
      cancelButtonColor: '#3085d6',
      confirmButtonText: 'Yes, delete it!'
    });

    if (result.isConfirmed) {
      try {
        await Api.send('api/supplier_products.php', 'DELETE', { id: productId });
        Swal.fire('Deleted!', 'The product has been deactivated.', 'success');
        loadSupplierProducts();
      } catch (err) {
        Swal.fire('Error', `Failed to delete product: ${err.message}`, 'error');
      }
    }
  };

  

  // ============================================
  // SUPPLIER PERFORMANCE FUNCTIONS
  // ============================================

  // Load Supplier Performance
  async function loadSupplierPerformance() {
    try {
      const container = document.getElementById('performance-list');
      container.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2">Loading performance data...</p></div>';
      
      // First get all suppliers
      const suppliersResponse = await Api.get('api/suppliers.php');
      const suppliers = suppliersResponse.data.items || [];
      
      // Then get performance for each
      const performancePromises = suppliers.map(supplier => 
        Api.get(`api/supplier_performance.php?supplier_id=${supplier.id}`)
          .then(response => ({ ...response.data.performance, supplier_name: supplier.name, supplier_code: supplier.code, supplier_id: supplier.id }))
          .catch(() => null)
      );
      
      const performances = (await Promise.all(performancePromises)).filter(p => p !== null);
      
      if (performances.length === 0) {
        container.innerHTML = '<div class="alert alert-info">No performance data available.</div>';
        return;
      }
      
      // Calculate summary stats
      const totalSuppliers = performances.length;
      const avgOnTimeRate = (performances.reduce((sum, p) => sum + parseFloat(p.on_time_rate || 0), 0) / totalSuppliers).toFixed(1);
      const avgQualityRate = (performances.reduce((sum, p) => sum + parseFloat(p.quality_rate || 0), 0) / totalSuppliers).toFixed(1);
      const avgRating = (performances.reduce((sum, p) => sum + parseFloat(p.overall_rating || 0), 0) / totalSuppliers).toFixed(2);
      
      // Update summary cards
      document.getElementById('total-suppliers-count').textContent = totalSuppliers;
      document.getElementById('avg-ontime-rate').textContent = avgOnTimeRate + '%';
      document.getElementById('avg-quality-rate').textContent = avgQualityRate + '%';
      document.getElementById('avg-rating').textContent = avgRating + ' ⭐';
      
      // Generate performance cards
      const cards = performances.map(perf => {
        const stars = '⭐'.repeat(Math.round(parseFloat(perf.overall_rating || 0)));
        
        return `
          <div class="col-md-6 col-lg-4 mb-3">
            <div class="card h-100">
              <div class="card-body">
                <h6 class="card-title">${perf.supplier_name}</h6>
                <p class="text-muted small mb-2">${perf.supplier_code}</p>
                <div class="mb-2">
                  <h4>${stars} <small class="text-muted">${parseFloat(perf.overall_rating || 0).toFixed(2)}</small></h4>
                </div>
                <hr>
                <div class="row g-2 small">
                  <div class="col-6">
                    <strong>Total POs:</strong> ${perf.total_pos || 0}
                  </div>
                  <div class="col-6">
                    <strong>On-Time:</strong> <span class="badge bg-success">${parseFloat(perf.on_time_rate || 0).toFixed(1)}%</span>
                  </div>
                  <div class="col-6">
                    <strong>Quality:</strong> <span class="badge bg-info">${parseFloat(perf.quality_rate || 0).toFixed(1)}%</span>
                  </div>
                  <div class="col-6">
                    <strong>Response:</strong> ${parseFloat(perf.avg_response_time_hours || 0).toFixed(1)}h
                  </div>
                </div>
                <hr>
                <button class="btn btn-sm btn-outline-primary w-100" onclick="viewDetailedPerformance(${perf.supplier_id})">
                  <i class="fa-solid fa-chart-bar me-1"></i>View Details
                </button>
              </div>
            </div>
          </div>
        `;
      }).join('');
      
      container.innerHTML = `<div class="row">${cards}</div>`;
    } catch (err) {
      document.getElementById('performance-list').innerHTML = `<div class="alert alert-danger">Failed to load performance data: ${err.message}</div>`;
    }
  }

  // View Detailed Performance
  window.viewDetailedPerformance = async function(supplierId) {
    try {
      const response = await Api.get(`api/supplier_performance.php?supplier_id=${supplierId}`);
      const data = response.data;
      const supplier = data.supplier;
      const perf = data.performance;
      
      const stars = '⭐'.repeat(Math.round(parseFloat(perf.overall_rating || 0)));
      
      Swal.fire({
        title: `Performance: ${supplier.name}`,
        html: `
          <div class="text-start">
            <div class="text-center mb-3">
              <h2>${stars}</h2>
              <h4>${parseFloat(perf.overall_rating || 0).toFixed(2)} / 5.00</h4>
            </div>
            <hr>
            <h6>Delivery Performance</h6>
            <p><strong>Total POs:</strong> ${perf.total_pos || 0}</p>
            <p><strong>On-Time Deliveries:</strong> ${perf.on_time_deliveries || 0} (${parseFloat(perf.on_time_rate || 0).toFixed(1)}%)</p>
            <p><strong>Late Deliveries:</strong> ${perf.late_deliveries || 0}</p>
            <hr>
            <h6>Quality Performance</h6>
            <p><strong>Total Items Received:</strong> ${perf.total_items_received || 0}</p>
            <p><strong>Passed QC:</strong> ${perf.items_passed_qc || 0} (${parseFloat(perf.quality_rate || 0).toFixed(1)}%)</p>
            <p><strong>Failed QC:</strong> ${perf.items_failed_qc || 0}</p>
            <hr>
            <h6>Response Time</h6>
            <p><strong>Average Response:</strong> ${parseFloat(perf.avg_response_time_hours || 0).toFixed(1)} hours</p>
            <hr>
            <p class="text-muted small">Last calculated: ${perf.last_calculated_at ? new Date(perf.last_calculated_at).toLocaleString() : '-'}</p>
          </div>
        `,
        width: '600px',
        showCancelButton: true,
        confirmButtonText: 'Recalculate',
        cancelButtonText: 'Close'
      }).then(async (result) => {
        if (result.isConfirmed) {
          await recalculatePerformance(supplierId);
        }
      });
    } catch (err) {
      Swal.fire('Error', `Failed to load performance details: ${err.message}`, 'error');
    }
  };

  // Recalculate Performance
  async function recalculatePerformance(supplierId) {
    try {
      Swal.fire({
        title: 'Recalculating...',
        html: '<div class="spinner-border text-primary"></div>',
        showConfirmButton: false,
        allowOutsideClick: false
      });
      
      await Api.send('api/supplier_performance.php', 'POST', { supplier_id: supplierId });
      
      await Swal.fire({
        icon: 'success',
        title: 'Performance Recalculated!',
        timer: 2000
      });
      
      loadSupplierPerformance();
    } catch (err) {
      Swal.fire('Error', `Failed to recalculate: ${err.message}`, 'error');
    }
  }

  // Recalculate All Performance
  window.recalculateAllPerformance = async function() {
    try {
      const result = await Swal.fire({
        title: 'Recalculate All?',
        text: 'This will recalculate performance metrics for all suppliers.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, recalculate'
      });
      
      if (!result.isConfirmed) return;
      
      Swal.fire({
        title: 'Recalculating All...',
        html: '<div class="spinner-border text-primary"></div>',
        showConfirmButton: false,
        allowOutsideClick: false
      });
      
      // Get all suppliers
      const suppliersResponse = await Api.get('api/suppliers.php');
      const suppliers = suppliersResponse.data.items || [];
      
      // Recalculate each
      for (const supplier of suppliers) {
        await Api.send('api/supplier_performance.php', 'POST', { supplier_id: supplier.id });
      }
      
      await Swal.fire({
        icon: 'success',
        title: 'All Performance Recalculated!',
        timer: 2000
      });
      
      loadSupplierPerformance();
    } catch (err) {
      Swal.fire('Error', `Failed to recalculate: ${err.message}`, 'error');
    }
  };

  // ============================================
  // EVENT LISTENERS
  // ============================================

  // Goods Receipt tab
  document.getElementById('goods-receipt-tab').addEventListener('shown.bs.tab', () => {
    window.location.hash = 'tab=goods-receipt';
    loadGoodsReceipts();
  });

  // Performance tab
  document.getElementById('performance-tab').addEventListener('shown.bs.tab', () => {
    window.location.hash = 'tab=performance';
    loadSupplierPerformance();
  });

  // Receive Goods button
  document.getElementById('receive-goods-btn').addEventListener('click', receiveGoods);

  // Receipt filters
  document.getElementById('receipt-status-filter').addEventListener('change', (e) => {
    const qcStatus = document.getElementById('receipt-qc-filter').value;
    const search = document.getElementById('receipt-search').value;
    loadGoodsReceipts(e.target.value, qcStatus, search);
  });

  document.getElementById('receipt-qc-filter').addEventListener('change', (e) => {
    const status = document.getElementById('receipt-status-filter').value;
    const search = document.getElementById('receipt-search').value;
    loadGoodsReceipts(status, e.target.value, search);
  });

  let receiptSearchTimeout;
  document.getElementById('receipt-search').addEventListener('input', (e) => {
    clearTimeout(receiptSearchTimeout);
    receiptSearchTimeout = setTimeout(() => {
      const status = document.getElementById('receipt-status-filter').value;
      const qcStatus = document.getElementById('receipt-qc-filter').value;
      loadGoodsReceipts(status, qcStatus, e.target.value);
    }, 300);
  });

  document.getElementById('refresh-receipts-btn').addEventListener('click', () => {
    loadGoodsReceipts();
  });

  // Recalculate all performance button
  document.getElementById('recalculate-all-performance-btn').addEventListener('click', recalculateAllPerformance);

})();
