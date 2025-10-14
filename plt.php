<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>DropIT · PLT</title>
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
        <div class="card shadow-sm">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <h5 class="card-title mb-0"><i class="fa-solid fa-truck-fast me-2 text-primary"></i>PLT – Shipments</h5>
              <div class="d-flex gap-2">
                <select id="status" class="form-select form-select-sm" style="width:180px">
                  <option value="">All Status</option>
                  <option value="pending">Pending</option>
                  <option value="picked">Picked</option>
                  <option value="packed">Packed</option>
                  <option value="in_transit">In Transit</option>
                  <option value="out_for_delivery">Out for Delivery</option>
                  <option value="delivered">Delivered</option>
                  <option value="failed">Failed</option>
                  <option value="returned">Returned</option>
                </select>
                <input id="search" class="form-control form-control-sm" style="width:160px" placeholder="Search tracking #"/>
                <button id="filterBtn" class="btn btn-outline-primary btn-sm">Apply</button>
                <button id="scanBarcodeBtn" class="btn btn-success btn-sm"><i class="fa-solid fa-qrcode"></i> Scan Barcode</button>
                <button id="createBtn" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> New Shipment</button>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Tracking #</th><th>Customer</th><th>Destination</th><th>Status</th><th>Priority</th><th>Est. Delivery</th><th>Actions</th>
                  </tr>
                </thead>
                <tbody id="rows"><tr><td colspan="7" class="text-muted">Loading…</td></tr></tbody>
              </table>
            </div>
            <div class="d-flex align-items-center gap-2">
              <button id="prev" class="btn btn-outline-secondary btn-sm">Prev</button>
              <button id="next" class="btn btn-outline-secondary btn-sm">Next</button>
              <span id="meta" class="text-muted small"></span>
            </div>
          </div>
        </div>
      </section>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/common.js"></script>
  <script>
    let page=1, limit=10, status='', search='';
    
    async function load(){
      try {
        const res = await Api.get(`api/plt_shipments.php${Api.q({status, search, page, limit})}`);
        const items = res.data.shipments||[]; 
        const total = res.data.pagination?.total||0;
        
        document.getElementById('rows').innerHTML = items.map(s=>{
          const statusColor = {
            'pending': 'secondary', 'picked': 'info', 'packed': 'warning', 
            'in_transit': 'primary', 'out_for_delivery': 'warning', 
            'delivered': 'success', 'failed': 'danger', 'returned': 'dark'
          }[s.status] || 'secondary';
          
          const priorityColor = {
            'standard': 'secondary', 'express': 'warning', 'urgent': 'danger'
          }[s.priority] || 'secondary';
          
          return `<tr>
            <td><code>${s.tracking_number}</code></td>
            <td>${s.customer_name}</td>
            <td>${s.city || s.delivery_address}</td>
            <td><span class="badge bg-${statusColor}">${s.status}</span></td>
            <td><span class="badge bg-${priorityColor}">${s.priority}</span></td>
            <td>${s.estimated_delivery ? new Date(s.estimated_delivery).toLocaleDateString() : '-'}</td>
            <td>
              <button class="btn btn-sm btn-outline-primary" onclick="viewShipment(${s.id})">
                <i class="fa-solid fa-eye"></i>
              </button>
              <button class="btn btn-sm btn-outline-info" onclick="trackShipment('${s.tracking_number}')">
                <i class="fa-solid fa-location-dot"></i>
              </button>
            </td>
          </tr>`;
        }).join('');
        
        document.getElementById('meta').textContent = `Page ${page} of ${Math.ceil(total/limit)} (${total} total)`;
        document.getElementById('prev').disabled = page <= 1;
        document.getElementById('next').disabled = page >= Math.ceil(total/limit);
        
      } catch(e) {
        document.getElementById('rows').innerHTML = '<tr><td colspan="7" class="text-danger">Error loading shipments</td></tr>';
        console.error('Load error:', e);
      }
    }
    
    async function viewShipment(id) {
      try {
        const res = await Api.get(`api/plt_shipments.php?id=${id}`);
        const data = res.data;
        
        const itemsHtml = (data.items || []).map(item => 
          `<tr>
            <td>${item.product_name || item.product_id}</td>
            <td>${item.sku || 'N/A'}</td>
            <td>${item.quantity}</td>
            <td>${item.weight_kg || 0} kg</td>
            <td>₱${parseFloat(item.value || 0).toFixed(2)}</td>
          </tr>`
        ).join('');
        
        const eventsHtml = (data.tracking_events || []).map(e => 
          `<div class="border-bottom py-2">
            <strong>${e.event_type}</strong> - ${e.status}<br>
            <small class="text-muted">${new Date(e.event_time).toLocaleString()}</small>
            ${e.description ? `<br><small>${e.description}</small>` : ''}
          </div>`
        ).join('');
        
        const statusOptions = ['pending', 'picked', 'packed', 'in_transit', 'out_for_delivery', 'delivered', 'failed', 'returned'];
        const statusSelect = statusOptions.map(status => 
          `<option value="${status}" ${status === data.status ? 'selected' : ''}>${status.replace('_', ' ').toUpperCase()}</option>`
        ).join('');
        
        await Swal.fire({
          title: `Shipment ${data.tracking_number}`,
          html: `
            <div class="text-start">
              <div class="row mb-3">
                <div class="col-md-6">
                  <strong>Status:</strong> <span class="badge bg-primary">${data.status}</span>
                </div>
                <div class="col-md-6">
                  <strong>Priority:</strong> <span class="badge bg-secondary">${data.priority}</span>
                </div>
              </div>
              <div class="row mb-3">
                <div class="col-md-6">
                  <strong>Customer:</strong> ${data.customer_name}
                </div>
                <div class="col-md-6">
                  <strong>Warehouse:</strong> ${data.warehouse_name || 'N/A'}
                </div>
              </div>
              <div class="mb-3">
                <strong>Delivery Address:</strong><br>
                <small>${data.delivery_address}</small>
              </div>
              ${data.estimated_delivery ? `<div class="mb-3"><strong>Est. Delivery:</strong> ${new Date(data.estimated_delivery).toLocaleString()}</div>` : ''}
              
              <div class="mb-3">
                <strong>Items (${(data.items || []).length}):</strong>
                <table class="table table-sm mt-2">
                  <thead>
                    <tr><th>Product</th><th>SKU</th><th>Qty</th><th>Weight</th><th>Value</th></tr>
                  </thead>
                  <tbody>
                    ${itemsHtml || '<tr><td colspan="5" class="text-muted">No items found</td></tr>'}
                  </tbody>
                </table>
              </div>
              
              <div class="mb-3">
                <strong>Update Status:</strong>
                <div class="d-flex gap-2 mt-2">
                  <select id="new-status" class="form-control">
                    ${statusSelect}
                  </select>
                  <button id="update-status-btn" class="btn btn-primary btn-sm">Update</button>
                </div>
              </div>
              
              <div class="mb-3">
                <strong>Tracking Events:</strong>
                <div class="mt-2" style="max-height: 200px; overflow-y: auto;">
                  ${eventsHtml || '<small class="text-muted">No tracking events</small>'}
                </div>
              </div>
            </div>
          `,
          width: '800px',
          showCancelButton: false,
          confirmButtonText: 'Close',
          didOpen: () => {
            document.getElementById('update-status-btn').addEventListener('click', async () => {
              const newStatus = document.getElementById('new-status').value;
              if (newStatus !== data.status) {
                await updateShipmentStatus(id, newStatus);
                Swal.close();
                load();
              }
            });
          }
        });
      } catch(e) {
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'Failed to load shipment details',
        });
      }
    }
    
    async function updateShipmentStatus(shipmentId, newStatus) {
      try {
        await Api.send('api/plt_shipments.php?action=update_status', 'PUT', {
          shipment_id: shipmentId,
          status: newStatus,
          notes: `Status updated to ${newStatus}`
        });
        
        Swal.fire({
          icon: 'success',
          title: 'Status Updated!',
          text: `Shipment status changed to ${newStatus}`,
          timer: 2000,
          showConfirmButton: false
        });
      } catch(e) {
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'Failed to update status',
        });
      }
    }
    
    async function trackShipment(trackingNumber) {
      try {
        const res = await Api.get(`api/plt_shipments.php?action=track&tracking_number=${trackingNumber}`);
        const data = res.data;
        
        const eventsHtml = (data.events || []).map(e => 
          `<div class="border-bottom py-2">
            <strong>${e.event_type}</strong> - ${e.status}<br>
            <small class="text-muted">${new Date(e.event_time).toLocaleString()}</small>
            ${e.description ? `<br><small>${e.description}</small>` : ''}
          </div>`
        ).join('');
        
        await Swal.fire({
          title: `Track ${trackingNumber}`,
          html: `
            <div class="text-start">
              <div class="mb-3">
                <strong>Status:</strong> <span class="badge bg-primary">${data.status}</span>
              </div>
              <div class="mb-3">
                <strong>Customer:</strong> ${data.customer_name}
              </div>
              ${data.estimated_delivery ? `<div class="mb-3"><strong>Est. Delivery:</strong> ${new Date(data.estimated_delivery).toLocaleString()}</div>` : ''}
              <div class="mb-3">
                <strong>Tracking Events:</strong>
                <div class="mt-2" style="max-height: 200px; overflow-y: auto;">
                  ${eventsHtml}
                </div>
              </div>
            </div>
          `,
          width: '600px',
          confirmButtonText: 'Close'
        });
      } catch(e) {
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'Failed to load tracking information',
        });
      }
    }
    
    async function openCreateModal(){
      try {
        const itemsRes = await Api.get('api/shipping_items.php?warehouse_id=1');
        const availableItems = itemsRes.data.items || [];
        
        if (availableItems.length === 0) {
          Swal.fire({
            icon: 'warning',
            title: 'No Items Available',
            text: 'No items are available in the shipping zone. Please check SWS inventory.',
          });
          return;
        }
        
        const itemOptions = availableItems.map(item => 
          `<option value="${item.product_id}" data-name="${item.product_name}" data-sku="${item.sku}" data-max="${item.available_quantity}">
            ${item.product_name} (${item.sku}) - ${item.available_quantity} available
          </option>`
        ).join('');
        
        const { value: formData } = await Swal.fire({
          title: 'Create New Shipment',
          html: `
            <div class="text-start">
              <div class="mb-3">
                <label class="form-label">Select Item *</label>
                <select id="item-select" class="form-control">
                  <option value="">Choose an item...</option>
                  ${itemOptions}
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Quantity *</label>
                <input id="item-qty" type="number" class="form-control" min="1" placeholder="Enter quantity">
              </div>
              <div class="mb-3">
                <label class="form-label">Customer Name *</label>
                <input id="customer-name" class="form-control" placeholder="Enter customer name">
              </div>
              <div class="mb-3">
                <label class="form-label">Customer Email</label>
                <input id="customer-email" type="email" class="form-control" placeholder="Enter email (optional)">
              </div>
              <div class="mb-3">
                <label class="form-label">Customer Phone</label>
                <input id="customer-phone" class="form-control" placeholder="Enter phone (optional)">
              </div>
              <div class="mb-3">
                <label class="form-label">Delivery Address *</label>
                <textarea id="delivery-address" class="form-control" rows="3" placeholder="Enter full delivery address"></textarea>
              </div>
              <div class="mb-3">
                <label class="form-label">City</label>
                <input id="city" class="form-control" placeholder="Enter city">
              </div>
              <div class="mb-3">
                <label class="form-label">Priority</label>
                <select id="priority" class="form-control">
                  <option value="standard">Standard</option>
                  <option value="express">Express</option>
                  <option value="urgent">Urgent</option>
                </select>
              </div>
            </div>
          `,
          width: '600px',
          focusConfirm: false,
          showCancelButton: true,
          confirmButtonText: 'Create Shipment',
          cancelButtonText: 'Cancel',
          preConfirm: () => {
            const itemSelect = document.getElementById('item-select');
            const itemQty = document.getElementById('item-qty');
            const customerName = document.getElementById('customer-name');
            const deliveryAddress = document.getElementById('delivery-address');
            
            if (!itemSelect.value) {
              Swal.showValidationMessage('Please select an item');
              return false;
            }
            
            if (!itemQty.value || itemQty.value < 1) {
              Swal.showValidationMessage('Please enter a valid quantity');
              return false;
            }
            
            const selectedOption = itemSelect.selectedOptions[0];
            const maxQty = parseInt(selectedOption.dataset.max);
            
            if (parseInt(itemQty.value) > maxQty) {
              Swal.showValidationMessage(`Quantity cannot exceed ${maxQty} available units`);
              return false;
            }
            
            if (!customerName.value || !deliveryAddress.value) {
              Swal.showValidationMessage('Customer name and delivery address are required');
              return false;
            }
            
            return {
              item_id: itemSelect.value,
              item_name: selectedOption.dataset.name,
              item_sku: selectedOption.dataset.sku,
              quantity: parseInt(itemQty.value),
              customer_name: customerName.value,
              customer_email: document.getElementById('customer-email').value,
              customer_phone: document.getElementById('customer-phone').value,
              delivery_address: deliveryAddress.value,
              city: document.getElementById('city').value,
              priority: document.getElementById('priority').value
            };
          }
        });
        
        if (formData) {
          await createShipment({
            customer_name: formData.customer_name,
            customer_email: formData.customer_email,
            customer_phone: formData.customer_phone,
            delivery_address: formData.delivery_address,
            city: formData.city,
            priority: formData.priority,
            warehouse_id: 1,
            items: [{
              product_id: formData.item_id,
              product_name: formData.item_name,
              quantity: formData.quantity
            }]
          });
        }
        
      } catch (e) {
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'Failed to load available items: ' + e.message,
        });
      }
    }
    
    async function createShipment(data){
      try {
        const res = await Api.send('api/plt_shipments.php', 'POST', data);
        await Swal.fire({
          icon: 'success',
          title: 'Shipment Created!',
          text: `Tracking Number: ${res.data.tracking_number}`,
          confirmButtonText: 'OK'
        });
        load();
      } catch(e) {
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'Failed to create shipment. Please try again.',
        });
      }
    }
    
    function openBarcodeScanner() {
      // Check if barcode scanner HTML file exists, otherwise show manual input
      Swal.fire({
        title: 'Scan Barcode',
        html: `
          <div class="text-start">
            <div class="mb-3">
              <label class="form-label">Enter Barcode/Tracking Number</label>
              <input id="barcode-input" class="form-control" placeholder="Scan or type barcode..." autofocus>
            </div>
            <div class="text-center">
              <small class="text-muted">
                <i class="fa-solid fa-qrcode me-1"></i>
                Position barcode in front of camera or type manually
              </small>
            </div>
          </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Search',
        cancelButtonText: 'Cancel',
        preConfirm: () => {
          const barcode = document.getElementById('barcode-input').value.trim();
          if (!barcode) {
            Swal.showValidationMessage('Please enter a barcode or tracking number');
            return false;
          }
          return barcode;
        },
        didOpen: () => {
          // Focus on input and select all text
          const input = document.getElementById('barcode-input');
          input.focus();
          
          // Listen for Enter key
          input.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
              Swal.clickConfirm();
            }
          });
        }
      }).then((result) => {
        if (result.isConfirmed && result.value) {
          searchByBarcode(result.value);
        }
      });
    }

    async function searchByBarcode(barcode) {
      try {
        // Search for shipment by barcode (could be tracking number or item barcode)
        document.getElementById('search').value = barcode;
        search = barcode;
        page = 1;
        
        // Show loading message
        Swal.fire({
          icon: 'info',
          title: 'Searching...',
          text: `Looking for: ${barcode}`,
          timer: 1500,
          showConfirmButton: false
        });
        
        // Perform search
        await load();
        
      } catch(e) {
        Swal.fire({
          icon: 'error',
          title: 'Search Error',
          text: 'Failed to search by barcode',
        });
      }
    }
    
    // Event listeners
    document.getElementById('createBtn').addEventListener('click', openCreateModal);
    document.getElementById('scanBarcodeBtn').addEventListener('click', openBarcodeScanner);
    document.getElementById('filterBtn').addEventListener('click', () => {
      status = document.getElementById('status').value;
      search = document.getElementById('search').value;
      page = 1;
      load();
    });
    document.getElementById('prev').addEventListener('click', () => { if(page > 1) { page--; load(); } });
    document.getElementById('next').addEventListener('click', () => { page++; load(); });
    
    // Load initial data
    load();
  </script>
</body>
</html>
