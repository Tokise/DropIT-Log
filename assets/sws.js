(function(){
  // Global variables
  let codeReader = null;
  let selectedDeviceId = null;
  let isScanning = false;
  let availableCameras = [];
  let currentCameraIndex = 0;

  // DOM elements
  const video = document.getElementById('scanner-video');
  const overlay = document.getElementById('scanner-overlay');
  const placeholder = document.getElementById('scanner-placeholder');
  const startBtn = document.getElementById('start-scanner');
  const stopBtn = document.getElementById('stop-scanner');
  const toggleBtn = document.getElementById('toggle-camera');
  const scanOutput = document.getElementById('scan-output');
  const quickAddForm = document.getElementById('quick-add-form');
  const addProductForm = document.getElementById('add-product-form');

  // Initialize barcode scanner
  async function initScanner() {
    try {
      codeReader = new ZXing.BrowserMultiFormatReader();
      availableCameras = await codeReader.listVideoInputDevices();
      
      if (availableCameras.length === 0) {
        showError('No cameras found. Please ensure camera permissions are granted.');
        return;
      }
      
      selectedDeviceId = availableCameras[0].deviceId;
      
      if (availableCameras.length > 1) {
        toggleBtn.style.display = 'inline-block';
      }
    } catch (err) {
      showError('Failed to initialize camera: ' + err.message);
    }
  }

  // Start barcode scanning
  async function startScanning() {
    if (!codeReader || isScanning) return;
    
    try {
      placeholder.style.display = 'none';
      video.style.display = 'block';
      overlay.style.display = 'block';
      startBtn.style.display = 'none';
      stopBtn.style.display = 'inline-block';
      
      isScanning = true;
      
      await codeReader.decodeFromVideoDevice(selectedDeviceId, video, (result, err) => {
        if (result) {
          handleBarcodeResult(result.text);
        }
        if (err && !(err instanceof ZXing.NotFoundException)) {
          console.error('Barcode scan error:', err);
        }
      });
    } catch (err) {
      showError('Failed to start scanning: ' + err.message);
      stopScanning();
    }
  }

  // Stop barcode scanning
  function stopScanning() {
    if (codeReader && isScanning) {
      codeReader.reset();
      isScanning = false;
    }
    
    video.style.display = 'none';
    overlay.style.display = 'none';
    placeholder.style.display = 'block';
    startBtn.style.display = 'inline-block';
    stopBtn.style.display = 'none';
  }

  // Switch camera
  async function switchCamera() {
    if (availableCameras.length <= 1) return;
    
    currentCameraIndex = (currentCameraIndex + 1) % availableCameras.length;
    selectedDeviceId = availableCameras[currentCameraIndex].deviceId;
    
    if (isScanning) {
      stopScanning();
      await startScanning();
    }
  }

  // Handle barcode scan result
  async function handleBarcodeResult(barcode) {
    try {
      scanOutput.innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm me-2"></div>Looking up barcode...</div>';
      
      // Look up product by barcode
      const response = await Api.get(`api/barcode_lookup.php?barcode=${encodeURIComponent(barcode)}`);
      
      if (response.data.found) {
        if (response.data.source === 'local') {
          displayProductInfo(response.data);
        } else {
          displayExternalProductInfo(response.data);
        }
      } else {
        displayNewProductForm(barcode, response.data.error);
      }
    } catch (err) {
      showError('Failed to lookup barcode: ' + err.message);
    }
  }

  // Display existing product information
  function displayProductInfo(data) {
    const product = data.product;
    const inventory = data.inventory;
    
    let inventoryHtml = '';
    if (inventory.length > 0) {
      inventoryHtml = inventory.map(inv => 
        `<div class="d-flex justify-content-between">
          <span>${inv.warehouse_name}</span>
          <span class="badge bg-primary">${inv.available_quantity} available</span>
        </div>`
      ).join('');
    } else {
      inventoryHtml = '<div class="text-muted">No inventory found</div>';
    }
    
    scanOutput.innerHTML = `
      <div class="scanner-result">
        <h6><i class="fa-solid fa-check-circle text-success me-2"></i>Product Found</h6>
        <div class="row">
          <div class="col-6">
            <strong>${product.name}</strong><br>
            <small class="text-muted">SKU: ${product.sku}</small><br>
            <small class="text-muted">Price: ₱${parseFloat(product.unit_price).toFixed(2)}</small>
          </div>
          <div class="col-6">
            <div class="small">
              <strong>Inventory:</strong><br>
              ${inventoryHtml}
            </div>
          </div>
        </div>
        <div class="mt-2">
          <button class="btn btn-sm btn-primary me-2" onclick="adjustInventoryForProduct(${product.id})">
            <i class="fa-solid fa-plus-minus me-1"></i>Adjust Stock
          </button>
          <button class="btn btn-sm btn-outline-primary" onclick="viewProductDetails(${product.id})">
            <i class="fa-solid fa-eye me-1"></i>View Details
          </button>
        </div>
      </div>
    `;
    
    quickAddForm.style.display = 'none';
  }

  // Display external product information from API
  function displayExternalProductInfo(data) {
    const product = data.external_product;
    const source = data.source;
    
    scanOutput.innerHTML = `
      <div class="alert alert-info">
        <h6><i class="fa-solid fa-cloud me-2"></i>Product Found via ${source.charAt(0).toUpperCase() + source.slice(1)}</h6>
        <div class="row">
          <div class="col-8">
            <strong>${product.name}</strong><br>
            <small class="text-muted">Brand: ${product.brand || 'Unknown'}</small><br>
            <small class="text-muted">Category: ${product.category || 'General'}</small><br>
            ${product.description ? `<small class="text-muted">${product.description}</small>` : ''}
          </div>
          <div class="col-4 text-end">
            ${product.image_url ? `<img src="${product.image_url}" class="img-thumbnail" style="max-width:80px;">` : '<i class="fa-solid fa-image fa-3x text-muted"></i>'}
          </div>
        </div>
        <div class="mt-2">
          <button class="btn btn-success btn-sm" onclick="createProductFromExternal('${data.barcode}')">
            <i class="fa-solid fa-plus me-1"></i>Add to Inventory
          </button>
        </div>
      </div>
    `;
    
    // Store external product data for later use
    window.externalProductData = product;
    quickAddForm.style.display = 'none';
  }

  // Display form for new product
  function displayNewProductForm(barcode, error = null) {
    const errorMsg = error ? `<br><small class="text-muted">Error: ${error}</small>` : '';
    
    scanOutput.innerHTML = `
      <div class="alert alert-warning">
        <i class="fa-solid fa-exclamation-triangle me-2"></i>
        <strong>Product not found</strong><br>
        Barcode: <code>${barcode}</code>${errorMsg}
      </div>
    `;
    
    document.getElementById('scanned-barcode').value = barcode;
    document.getElementById('product-name').value = '';
    document.getElementById('product-price').value = '';
    document.getElementById('product-description').value = `Product scanned with barcode: ${barcode}`;
    quickAddForm.style.display = 'block';
  }

  // Load warehouses for dropdown
  async function loadWarehouses() {
    try {
      const response = await Api.get('api/warehouses.php?limit=100');
      const select = document.getElementById('product-warehouse');
      select.innerHTML = '<option value="">Select warehouse</option>';
      
      response.data.items.forEach(warehouse => {
        select.innerHTML += `<option value="${warehouse.id}">${warehouse.name} (${warehouse.code})</option>`;
      });
    } catch (err) {
      console.error('Failed to load warehouses:', err);
    }
  }

  // Load inventory list
  let currentInventoryPage = 1;
  const inventoryPerPage = 15;
  
  async function loadInventory(page = 1) {
    try {
      currentInventoryPage = page;
      const response = await Api.get(`api/inventory.php?page=${page}&limit=${inventoryPerPage}`);
      const container = document.getElementById('inventory-list');
      
      if (response.data.items.length === 0) {
        container.innerHTML = '<div class="text-muted">No inventory items found</div>';
        return;
      }
      
      const rows = response.data.items.map(item => {
        const productImage = item.product_image 
          ? `<img src="${item.product_image}" alt="${item.product_name}" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;">`
          : '<i class="fa-solid fa-image text-muted" style="font-size: 24px;"></i>';
        
        return `
          <tr>
            <td>${productImage}</td>
            <td>${item.sku}</td>
            <td>${item.product_name}</td>
            <td>${item.warehouse_name}</td>
            <td><span class="badge bg-primary">${item.quantity}</span></td>
            <td><span class="badge bg-success">${item.available_quantity}</span></td>
            <td>
              <span class="text-muted" id="location-${item.id}" onclick="editLocation(${item.id}, '${item.location_code || ''}')" style="cursor: pointer;" title="Click to edit location">
                ${item.location_code || 'Not set'}
              </span>
            </td>
            <td>
              <button class="btn btn-sm btn-outline-primary me-1" onclick="adjustInventoryForItem(${item.id}, ${item.product_id}, ${item.warehouse_id})" title="Adjust Quantity">
                <i class="fa-solid fa-plus-minus"></i>
              </button>
              <button class="btn btn-sm btn-outline-info" onclick="viewInventoryHistory(${item.product_id}, ${item.warehouse_id})" title="View History">
                <i class="fa-solid fa-history"></i>
              </button>
            </td>
          </tr>
        `;
      }).join('');
      
      // Pagination
      const totalPages = Math.ceil(response.data.total / inventoryPerPage);
      let paginationHtml = '';
      
      if (totalPages > 1) {
        paginationHtml = '<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">';
        
        // Previous button
        paginationHtml += `<li class="page-item ${currentInventoryPage === 1 ? 'disabled' : ''}">
          <a class="page-link" href="#" onclick="loadInventory(${currentInventoryPage - 1}); return false;">Previous</a>
        </li>`;
        
        // Page numbers
        for (let i = 1; i <= totalPages; i++) {
          if (i === 1 || i === totalPages || (i >= currentInventoryPage - 2 && i <= currentInventoryPage + 2)) {
            paginationHtml += `<li class="page-item ${i === currentInventoryPage ? 'active' : ''}">
              <a class="page-link" href="#" onclick="loadInventory(${i}); return false;">${i}</a>
            </li>`;
          } else if (i === currentInventoryPage - 3 || i === currentInventoryPage + 3) {
            paginationHtml += '<li class="page-item disabled"><span class="page-link">...</span></li>';
          }
        }
        
        // Next button
        paginationHtml += `<li class="page-item ${currentInventoryPage === totalPages ? 'disabled' : ''}">
          <a class="page-link" href="#" onclick="loadInventory(${currentInventoryPage + 1}); return false;">Next</a>
        </li>`;
        
        paginationHtml += '</ul></nav>';
      }
      
      container.innerHTML = `
        <div class="table-responsive">
          <table class="table table-sm table-hover">
            <thead>
              <tr>
                <th>Image</th>
                <th>SKU</th>
                <th>Product</th>
                <th>Warehouse</th>
                <th>Total Qty</th>
                <th>Available</th>
                <th>Location</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        ${paginationHtml}
        <div class="text-muted small text-center mt-2">
          Showing ${((currentInventoryPage - 1) * inventoryPerPage) + 1} to ${Math.min(currentInventoryPage * inventoryPerPage, response.data.total)} of ${response.data.total} items
        </div>
      `;
    } catch (err) {
      document.getElementById('inventory-list').innerHTML = `<div class="alert alert-danger">Failed to load inventory: ${err.message}</div>`;
    }
  }

  // Load products list
  let currentProductsPage = 1;
  const productsPerPage = 15;
  
  async function loadProducts(search = '', page = 1) {
    try {
      currentProductsPage = page;
      const url = search 
        ? `api/products.php?q=${encodeURIComponent(search)}&page=${page}&limit=${productsPerPage}` 
        : `api/products.php?page=${page}&limit=${productsPerPage}`;
      const response = await Api.get(url);
      const container = document.getElementById('products-list');
      
      if (response.data.items.length === 0) {
        container.innerHTML = '<div class="text-muted">No products found</div>';
        return;
      }
      
      const rows = response.data.items.map(product => {
        const productImage = product.product_image 
          ? `<img src="${product.product_image}" alt="${product.name}" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;">`
          : '<i class="fa-solid fa-image text-muted" style="font-size: 24px;"></i>';
        
        return `
          <tr>
            <td>${productImage}</td>
            <td>${product.sku}</td>
            <td>${product.name}</td>
            <td>₱${parseFloat(product.unit_price).toFixed(2)}</td>
            <td>${product.barcode || '-'}</td>
            <td><span class="badge ${product.is_active ? 'bg-success' : 'bg-secondary'}">${product.is_active ? 'Active' : 'Inactive'}</span></td>
            <td>
              <button class="btn btn-sm btn-outline-primary me-1" onclick="viewProductDetails(${product.id})" title="View Details">
                <i class="fa-solid fa-eye"></i>
              </button>
              <button class="btn btn-sm btn-outline-danger" onclick="deleteProduct(${product.id}, '${product.name.replace(/'/g, "\\'")}', '${product.sku}')" title="Delete Product">
                <i class="fa-solid fa-trash"></i>
              </button>
            </td>
          </tr>
        `;
      }).join('');
      
      // Pagination
      const totalPages = Math.ceil(response.data.total / productsPerPage);
      let paginationHtml = '';
      
      if (totalPages > 1) {
        paginationHtml = '<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">';
        
        // Previous button
        paginationHtml += `<li class="page-item ${currentProductsPage === 1 ? 'disabled' : ''}">
          <a class="page-link" href="#" onclick="loadProducts('', ${currentProductsPage - 1}); return false;">Previous</a>
        </li>`;
        
        // Page numbers
        for (let i = 1; i <= totalPages; i++) {
          if (i === 1 || i === totalPages || (i >= currentProductsPage - 2 && i <= currentProductsPage + 2)) {
            paginationHtml += `<li class="page-item ${i === currentProductsPage ? 'active' : ''}">
              <a class="page-link" href="#" onclick="loadProducts('', ${i}); return false;">${i}</a>
            </li>`;
          } else if (i === currentProductsPage - 3 || i === currentProductsPage + 3) {
            paginationHtml += '<li class="page-item disabled"><span class="page-link">...</span></li>';
          }
        }
        
        // Next button
        paginationHtml += `<li class="page-item ${currentProductsPage === totalPages ? 'disabled' : ''}">
          <a class="page-link" href="#" onclick="loadProducts('', ${currentProductsPage + 1}); return false;">Next</a>
        </li>`;
        
        paginationHtml += '</ul></nav>';
      }
      
      container.innerHTML = `
        <div class="table-responsive">
          <table class="table table-sm table-hover">
            <thead>
              <tr>
                <th>Image</th>
                <th>SKU</th>
                <th>Name</th>
                <th>Price</th>
                <th>Barcode</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        ${paginationHtml}
        <div class="text-muted small text-center mt-2">
          Showing ${((currentProductsPage - 1) * productsPerPage) + 1} to ${Math.min(currentProductsPage * productsPerPage, response.data.total)} of ${response.data.total} items
        </div>
      `;
    } catch (err) {
      document.getElementById('products-list').innerHTML = `<div class="alert alert-danger">Failed to load products: ${err.message}</div>`;
    }
  }

  // Load warehouses list
  let currentWarehousesPage = 1;
  const warehousesPerPage = 15;
  
  async function loadWarehousesList(page = 1) {
    try {
      currentWarehousesPage = page;
      const response = await Api.get(`api/warehouses.php?page=${page}&limit=${warehousesPerPage}`);
      const container = document.getElementById('warehouses-list');
      
      if (response.data.items.length === 0) {
        container.innerHTML = '<div class="text-muted">No warehouses found</div>';
        return;
      }
      
      const rows = response.data.items.map(warehouse => `
        <tr>
          <td>${warehouse.code}</td>
          <td>${warehouse.name}</td>
          <td>${warehouse.city || '-'}</td>
          <td>${warehouse.country || '-'}</td>
          <td><span class="badge ${warehouse.is_active ? 'bg-success' : 'bg-secondary'}">${warehouse.is_active ? 'Active' : 'Inactive'}</span></td>
          <td>
            <button class="btn btn-sm btn-outline-primary" onclick="viewWarehouseDetails(${warehouse.id})">
              <i class="fa-solid fa-eye"></i>
            </button>
          </td>
        </tr>
      `).join('');
      
      // Pagination
      const totalPages = Math.ceil(response.data.total / warehousesPerPage);
      let paginationHtml = '';
      
      if (totalPages > 1) {
        paginationHtml = '<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">';
        
        paginationHtml += `<li class="page-item ${currentWarehousesPage === 1 ? 'disabled' : ''}">
          <a class="page-link" href="#" onclick="loadWarehousesList(${currentWarehousesPage - 1}); return false;">Previous</a>
        </li>`;
        
        for (let i = 1; i <= totalPages; i++) {
          if (i === 1 || i === totalPages || (i >= currentWarehousesPage - 2 && i <= currentWarehousesPage + 2)) {
            paginationHtml += `<li class="page-item ${i === currentWarehousesPage ? 'active' : ''}">
              <a class="page-link" href="#" onclick="loadWarehousesList(${i}); return false;">${i}</a>
            </li>`;
          } else if (i === currentWarehousesPage - 3 || i === currentWarehousesPage + 3) {
            paginationHtml += '<li class="page-item disabled"><span class="page-link">...</span></li>';
          }
        }
        
        paginationHtml += `<li class="page-item ${currentWarehousesPage === totalPages ? 'disabled' : ''}">
          <a class="page-link" href="#" onclick="loadWarehousesList(${currentWarehousesPage + 1}); return false;">Next</a>
        </li>`;
        
        paginationHtml += '</ul></nav>';
      }
      
      container.innerHTML = `
        <div class="table-responsive">
          <table class="table table-sm table-hover">
            <thead>
              <tr>
                <th>Code</th>
                <th>Name</th>
                <th>City</th>
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
          Showing ${((currentWarehousesPage - 1) * warehousesPerPage) + 1} to ${Math.min(currentWarehousesPage * warehousesPerPage, response.data.total)} of ${response.data.total} items
        </div>
      `;
    } catch (err) {
      document.getElementById('warehouses-list').innerHTML = `<div class="alert alert-danger">Failed to load warehouses: ${err.message}</div>`;
    }
  }

  // Show error message
  function showError(message) {
    scanOutput.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-exclamation-triangle me-2"></i>${message}</div>`;
  }

  // Create product from external API data
  window.createProductFromExternal = async function(barcode) {
    if (!window.externalProductData) {
      showError('External product data not available');
      return;
    }
    
    const product = window.externalProductData;
    
    // Pre-fill the form with external data
    document.getElementById('scanned-barcode').value = barcode;
    document.getElementById('product-name').value = product.name;
    document.getElementById('product-price').value = product.suggested_price || '';
    document.getElementById('product-description').value = product.description || `${product.brand ? product.brand + ' - ' : ''}${product.name}`;
    
    quickAddForm.style.display = 'block';
    
    // Scroll to form
    quickAddForm.scrollIntoView({ behavior: 'smooth' });
  };

  // Global functions for button clicks
  window.adjustInventoryForProduct = function(productId) {
    // TODO: Implement inventory adjustment modal
    alert('Inventory adjustment feature coming soon!');
  };

  window.adjustInventoryForItem = function(itemId) {
    // TODO: Implement inventory adjustment modal
    alert('Inventory adjustment feature coming soon!');
  };

  window.viewProductDetails = function(productId) {
    // TODO: Implement product details modal
    alert('Product details feature coming soon!');
  };

  window.viewWarehouseDetails = function(warehouseId) {
    // TODO: Implement warehouse details modal
    alert('Warehouse details feature coming soon!');
  };

  // Show manual product creation modal
  window.showManualProductForm = function() {
    showProductModal();
  };

  // Create and show product modal
  function showProductModal() {
    // Remove existing modal if any
    const existingModal = document.getElementById('productModal');
    if (existingModal) existingModal.remove();
    
    const modalHtml = `
      <div class="modal fade" id="productModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Add New Product with AI Analysis</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <form id="manualProductForm">
                <div class="row">
                  <div class="col-md-6">
                    <div class="mb-3">
                      <label class="form-label">SKU <small class="text-muted">(auto-generated if empty)</small></label>
                      <input type="text" class="form-control" id="modal-sku" placeholder="Leave empty to auto-generate" readonly>
                      <div class="form-text">Will be generated automatically</div>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="mb-3">
                      <label class="form-label">Barcode <small class="text-muted">(auto-generated if empty)</small></label>
                      <input type="text" class="form-control" id="modal-barcode" placeholder="Leave empty to auto-generate" readonly>
                      <div class="form-text">Will be generated automatically</div>
                    </div>
                  </div>
                </div>
                
                <div class="row" id="generated-codes-display" style="display: none;">
                  <div class="col-12">
                    <div class="alert alert-success">
                      <h6><i class="fa-solid fa-check-circle me-2"></i>Product Created Successfully!</h6>
                      <div class="row">
                        <div class="col-md-6">
                          <strong>SKU:</strong> <span id="display-sku"></span><br>
                          <strong>Barcode:</strong> <span id="display-barcode"></span><br>
                          <strong>Location:</strong> <span id="display-location"></span>
                        </div>
                        <div class="col-md-6 text-center">
                          <div id="barcode-image-container"></div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-8">
                    <div class="mb-3">
                      <label class="form-label">Product Name *</label>
                      <input type="text" class="form-control" id="modal-name" required>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="mb-3">
                      <label class="form-label">Category *</label>
                      <select class="form-select" id="modal-category" required>
                        <option value="">Select or AI will suggest</option>
                      </select>
                      <div class="form-text">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="create-category-btn" style="display: none;">
                          <i class="fa-solid fa-plus me-1"></i>Create New Category
                        </button>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="mb-3">
                  <label class="form-label">Description</label>
                  <textarea class="form-control" id="modal-description" rows="3"></textarea>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="mb-3">
                      <label class="form-label">Unit Price *</label>
                      <input type="number" step="0.01" class="form-control" id="modal-price" required>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="mb-3">
                      <label class="form-label">Weight (kg)</label>
                      <input type="number" step="0.001" class="form-control" id="modal-weight">
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-4">
                    <div class="mb-3">
                      <label class="form-label">Reorder Point</label>
                      <input type="number" class="form-control" id="modal-reorder-point" value="10">
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="mb-3">
                      <label class="form-label">Reorder Quantity</label>
                      <input type="number" class="form-control" id="modal-reorder-qty" value="50">
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="mb-3">
                      <label class="form-label">Lead Time (days)</label>
                      <input type="number" class="form-control" id="modal-lead-time" value="7">
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="mb-3">
                      <label class="form-label">Initial Warehouse</label>
                      <select class="form-select" id="modal-warehouse">
                        <option value="">Select warehouse</option>
                      </select>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="mb-3">
                      <label class="form-label">Initial Quantity</label>
                      <input type="number" class="form-control" id="modal-quantity" min="0" value="1">
                      <div class="form-text">Set to 0 to skip inventory creation</div>
                    </div>
                  </div>
                </div>

                <!-- AI Image Analysis Section -->
                <div class="card mb-4">
                  <div class="card-header">
                    <h6 class="mb-0"><i class="fa-solid fa-robot me-2"></i>AI-Powered Product Analysis</h6>
                  </div>
                  <div class="card-body">
                    <div class="row">
                      <div class="col-md-6">
                        <div class="mb-3">
                          <label class="form-label">Product Image</label>
                          <input type="file" class="form-control" id="product-image" accept="image/*">
                          <div class="form-text">Upload an image for AI analysis and automatic categorization</div>
                        </div>
                        <div id="image-preview" class="text-center" style="display: none;">
                          <img id="preview-img" class="img-fluid rounded" style="max-height: 200px;">
                        </div>
                      </div>
                      <div class="col-md-6">
                        <div id="ai-analysis-results" style="display: none;">
                          <h6>AI Analysis Results</h6>
                          <div id="ai-suggestions" class="alert alert-info">
                            <div class="spinner-border spinner-border-sm me-2"></div>
                            Analyzing image...
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-12">
                    <div class="mb-3">
                      <label class="form-label">Suggested Location <small class="text-muted">(AI-generated based on category)</small></label>
                      <input type="text" class="form-control" id="modal-location" readonly>
                      <div class="form-text">Location will be automatically assigned based on product category</div>
                    </div>
                  </div>
                </div>
              </form>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-primary" id="saveProductBtn">
                <i class="fa-solid fa-save me-1"></i>Save Product
              </button>
            </div>
          </div>
        </div>
      </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Load warehouses and categories for the modal
    loadWarehousesForModal();
    loadCategoriesForModal();
    
    // Setup AI image analysis
    setupImageAnalysis();
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('productModal'));
    modal.show();
    
    // Reset modal when hidden
    document.getElementById('productModal').addEventListener('hidden.bs.modal', function () {
      // Reset form and display
      document.getElementById('manualProductForm').reset();
      document.getElementById('generated-codes-display').style.display = 'none';
      document.getElementById('saveProductBtn').style.display = 'block';
      document.getElementById('saveProductBtn').disabled = false;
      document.getElementById('saveProductBtn').innerHTML = '<i class="fa-solid fa-save me-1"></i>Save Product';
      document.querySelector('#productModal .modal-title').textContent = 'Add New Product';
      document.getElementById('barcode-image-container').innerHTML = '';
    });
    
    // Handle save button
    document.getElementById('saveProductBtn').addEventListener('click', saveManualProduct);
  }

  // Load warehouses for modal dropdown
  async function loadWarehousesForModal() {
    try {
      const response = await Api.get('api/warehouses.php?limit=100');
      const select = document.getElementById('modal-warehouse');
      select.innerHTML = '<option value="">Select warehouse</option>';
      
      response.data.items.forEach(warehouse => {
        select.innerHTML += `<option value="${warehouse.id}">${warehouse.name} (${warehouse.code})</option>`;
      });
    } catch (err) {
      console.error('Failed to load warehouses for modal:', err);
    }
  }

  // Load categories for modal dropdown
  async function loadCategoriesForModal() {
    try {
      const response = await Api.get('api/categories.php');
      const select = document.getElementById('modal-category');
      select.innerHTML = '<option value="">Select or AI will suggest</option>';
      
      // Check if response has the expected structure
      if (response && response.data && response.data.items && Array.isArray(response.data.items)) {
        response.data.items.forEach(category => {
          select.innerHTML += `<option value="${category.id}" data-prefix="${category.location_prefix || ''}" data-zone="${category.zone_letter || ''}" data-aisle="${category.aisle_range || ''}">${category.name}</option>`;
        });
      } else {
        console.warn('Categories response structure unexpected:', response);
        select.innerHTML += '<option value="">No categories available</option>';
      }
      
      // Handle category selection
      select.addEventListener('change', function() {
        if (this.value) {
          const selectedOption = this.options[this.selectedIndex];
          const prefix = selectedOption.dataset.prefix;
          const zone = selectedOption.dataset.zone;
          const aisle = selectedOption.dataset.aisle;
          if (prefix && zone && aisle) {
            updateLocationSuggestion(prefix, zone, aisle);
          }
        } else {
          document.getElementById('modal-location').value = '';
        }
      });
    } catch (err) {
      console.error('Failed to load categories:', err);
      const select = document.getElementById('modal-category');
      select.innerHTML = '<option value="">Error loading categories</option>';
    }
  }

  // Setup AI image analysis
  function setupImageAnalysis() {
    const imageInput = document.getElementById('product-image');
    const previewContainer = document.getElementById('image-preview');
    const previewImg = document.getElementById('preview-img');
    const analysisResults = document.getElementById('ai-analysis-results');
    
    imageInput.addEventListener('change', async function(e) {
      const file = e.target.files[0];
      if (!file) return;
      
      // Show image preview
      const reader = new FileReader();
      reader.onload = function(e) {
        previewImg.src = e.target.result;
        previewContainer.style.display = 'block';
      };
      reader.readAsDataURL(file);
      
      // Convert to base64 for AI analysis
      const base64 = await fileToBase64(file);
      
      // Show analysis section
      analysisResults.style.display = 'block';
      document.getElementById('ai-suggestions').innerHTML = `
        <div class="spinner-border spinner-border-sm me-2"></div>
        Analyzing image...
      `;
      
      try {
        // Perform AI analysis
        const analysisData = {
          image: base64,
          product_name: document.getElementById('modal-name').value,
          description: document.getElementById('modal-description').value
        };
        
        const response = await Api.send('api/ai_category_analyzer.php', 'POST', analysisData);
        
        // Display results
        displayAIAnalysisResults(response.data);
        
      } catch (err) {
        document.getElementById('ai-suggestions').innerHTML = `
          <div class="text-danger">
            <i class="fa-solid fa-exclamation-triangle me-2"></i>
            Analysis failed: ${err.message}
          </div>
        `;
      }
    });
  }

  // Display AI analysis results
  function displayAIAnalysisResults(data) {
    const suggestionsDiv = document.getElementById('ai-suggestions');
    const category = data.suggested_category;
    const confidence = data.confidence;
    const aiAnalysis = data.ai_analysis;
    
    let html = `
      <div class="ai-results">
        <h6><i class="fa-solid fa-brain me-2"></i>AI Analysis Complete</h6>
        <div class="mb-2">
          <strong>Detected Category:</strong> ${category.name}
          <span class="badge bg-${confidence > 70 ? 'success' : confidence > 50 ? 'warning' : 'secondary'} ms-2">
            ${confidence}% confidence
          </span>
        </div>
        <div class="mb-2">
          <strong>Description:</strong> ${aiAnalysis.description}
        </div>
        <div class="mb-3">
          <strong>Suggested Location:</strong> <code>${data.location_suggestion}</code>
        </div>
    `;
    
    // Check if AI suggested a new category OR if category doesn't exist
    if (data.create_new_category || aiAnalysis.is_new_category) {
      const categoryName = category.name || aiAnalysis.detected_category;
      const categoryDesc = category.description || `AI-suggested category for ${categoryName} items`;
      
      html += `
        <div class="alert alert-warning">
          <i class="fa-solid fa-lightbulb me-2"></i>
          <strong>New Category Suggested:</strong> "${categoryName}" doesn't exist in the database yet.
          <button class="btn btn-sm btn-primary ms-2" onclick="createAICategory('${categoryName}', '${categoryDesc}')">
            <i class="fa-solid fa-plus me-1"></i>Create Category
          </button>
        </div>
      `;
    } else {
      html += `
        <button class="btn btn-sm btn-success" onclick="applySuggestion(${category.id}, '${data.location_suggestion}')">
          <i class="fa-solid fa-check me-1"></i>Apply Suggestion
        </button>
      `;
    }
    
    html += '</div>';
    suggestionsDiv.innerHTML = html;
  }

  // Apply AI suggestion
  window.applySuggestion = function(categoryId, location) {
    console.log('Applying suggestion:', categoryId, location);
    document.getElementById('modal-category').value = categoryId;
    document.getElementById('modal-location').value = location;
    
    // Trigger change event to update location
    document.getElementById('modal-category').dispatchEvent(new Event('change'));
    
    // Show success message
    document.getElementById('ai-suggestions').innerHTML = `
      <div class="alert alert-success">
        <i class="fa-solid fa-check-circle me-2"></i>
        Category and location applied! You can now save the product.
      </div>
    `;
  };

  // Create new category from AI suggestion
  window.createAICategory = async function(name, description) {
    try {
      const response = await Api.send('api/create_ai_category.php', 'POST', {
        category_name: name,
        description: description
      });
      
      const data = response.data;
      
      // Reload categories
      await loadCategoriesForModal();
      
      // Select the new category
      document.getElementById('modal-category').value = data.category_id;
      
      // Update location
      updateLocationSuggestion(data.location_prefix, data.zone_letter, data.aisle_range);
      
      // Show success message
      Swal.fire({
        icon: 'success',
        title: data.already_exists ? 'Category Found' : 'Category Created',
        text: data.message,
        timer: 2000,
        showConfirmButton: false
      });
      
      // Clear AI suggestions
      document.getElementById('ai-suggestions').innerHTML = `
        <div class="alert alert-success">
          <i class="fa-solid fa-check-circle me-2"></i>
          Category "${name}" is now selected. You can proceed to save the product.
        </div>
      `;
      
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: 'Failed to Create Category',
        text: err.message
      });
    }
  };

  // Create new category (manual)
  window.createNewCategory = async function(name, description, prefix, zone, aisle) {
    try {
      const response = await Api.send('api/categories.php', 'POST', {
        name: name,
        description: description,
        location_prefix: prefix,
        zone_letter: zone,
        aisle_range: aisle
      });
      
      // Reload categories
      await loadCategoriesForModal();
      
      // Select the new category
      document.getElementById('modal-category').value = response.data.id;
      
      // Update location
      updateLocationSuggestion(prefix, zone, aisle);
      
      // Show success message
      Swal.fire({
        icon: 'success',
        title: 'Category Created',
        text: `Category "${name}" has been created successfully!`,
        timer: 2000,
        showConfirmButton: false
      });
      
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: 'Failed to Create Category',
        text: err.message
      });
    }
  };

  // Update location suggestion based on category
  function updateLocationSuggestion(prefix, zone, aisle) {
    const aisleRange = aisle.split('-');
    const aisleNum = aisleRange[0];
    const rack = Math.floor(Math.random() * 10) + 1;
    const shelf = Math.floor(Math.random() * 5) + 1;
    
    const location = `${prefix}-${zone}${aisleNum}-R${rack}-S${shelf}`;
    document.getElementById('modal-location').value = location;
  }

  // Convert file to base64
  function fileToBase64(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.readAsDataURL(file);
      reader.onload = () => resolve(reader.result);
      reader.onerror = error => reject(error);
    });
  }

  // Show barcode in a modal (global function)
  window.showBarcodeModal = async function(sku, barcode, productName, location = null) {
    try {
      // Show loading
      Swal.fire({
        title: 'Generating Barcode...',
        html: '<div class="spinner-border text-primary"></div>',
        showConfirmButton: false,
        allowOutsideClick: false
      });
      
      // Get barcode image
      const barcodeResponse = await Api.get(`api/barcode_image.php?barcode=${barcode}&format=dataurl&width=300&height=120`);
      
      let barcodeHtml = '';
      if (barcodeResponse.data && barcodeResponse.data.image) {
        barcodeHtml = `
          <div class="text-center mb-3">
            <img src="${barcodeResponse.data.image}" alt="Barcode" class="img-fluid" style="max-width: 100%; border: 2px solid #ddd; border-radius: 8px; padding: 10px; background: white;">
          </div>
        `;
      } else {
        barcodeHtml = `
          <div class="text-center mb-3">
            <div class="border p-3 bg-light" style="font-family: 'Courier New', monospace; font-size: 18px; letter-spacing: 2px;">
              ${barcode}
            </div>
          </div>
        `;
      }
      
      // Show barcode modal
      Swal.fire({
        title: 'Product Barcode',
        html: `
          <div class="text-start">
            <h6 class="mb-3 text-center">${productName}</h6>
            ${barcodeHtml}
            <div class="row text-start">
              <div class="col-6">
                <p class="mb-2"><strong>SKU:</strong></p>
                <p class="mb-2"><code>${sku}</code></p>
              </div>
              <div class="col-6">
                <p class="mb-2"><strong>Barcode:</strong></p>
                <p class="mb-2"><code>${barcode}</code></p>
              </div>
              ${location ? `
                <div class="col-12 mt-2">
                  <p class="mb-2"><strong>Location:</strong></p>
                  <p class="mb-2"><code>${location}</code></p>
                </div>
              ` : ''}
            </div>
            <div class="alert alert-info mt-3 small">
              <i class="fa-solid fa-info-circle me-2"></i>
              You can print this barcode or scan it to track inventory.
            </div>
          </div>
        `,
        width: '600px',
        showCloseButton: true,
        showCancelButton: true,
        confirmButtonText: '<i class="fa-solid fa-print me-2"></i>Print Barcode',
        cancelButtonText: 'Close',
        confirmButtonColor: '#0d6efd',
        cancelButtonColor: '#6c757d'
      }).then((result) => {
        if (result.isConfirmed) {
          // Print barcode
          printBarcode(barcodeResponse.data.image, productName, sku, barcode, location);
        }
      });
      
    } catch (err) {
      console.error('Failed to show barcode:', err);
      Swal.fire({
        icon: 'error',
        title: 'Failed to Generate Barcode',
        text: err.message
      });
    }
  };
  
  // Print barcode function (global function)
  window.printBarcode = function(barcodeImage, productName, sku, barcode, location) {
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
      <!DOCTYPE html>
      <html>
      <head>
        <title>Print Barcode - ${productName}</title>
        <style>
          body {
            font-family: Arial, sans-serif;
            padding: 20px;
            text-align: center;
          }
          .barcode-container {
            border: 2px solid #000;
            padding: 20px;
            display: inline-block;
            margin: 20px auto;
          }
          .barcode-image {
            max-width: 100%;
            margin: 10px 0;
          }
          .product-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
          }
          .details {
            font-size: 14px;
            margin-top: 10px;
          }
          @media print {
            body { padding: 0; }
            .no-print { display: none; }
          }
        </style>
      </head>
      <body>
        <div class="barcode-container">
          <div class="product-name">${productName}</div>
          <img src="${barcodeImage}" alt="Barcode" class="barcode-image">
          <div class="details">
            <div>SKU: ${sku}</div>
            <div>Barcode: ${barcode}</div>
            ${location ? `<div>Location: ${location}</div>` : ''}
          </div>
        </div>
        <div class="no-print" style="margin-top: 20px;">
          <button onclick="window.print()" style="padding: 10px 20px; font-size: 16px; cursor: pointer;">
            Print
          </button>
          <button onclick="window.close()" style="padding: 10px 20px; font-size: 16px; cursor: pointer; margin-left: 10px;">
            Close
          </button>
        </div>
      </body>
      </html>
    `);
    printWindow.document.close();
  };
  
  // Display generated codes with barcode image (legacy function - kept for compatibility)
  async function displayGeneratedCodes(sku, barcode, location = null) {
    try {
      // Show the generated codes display
      document.getElementById('generated-codes-display').style.display = 'block';
      document.getElementById('display-sku').textContent = sku;
      document.getElementById('display-barcode').textContent = barcode;
      if (location) {
        document.getElementById('display-location').textContent = location;
      }
      
      // Get barcode image (will check database first, then generate if needed)
      const barcodeResponse = await Api.get(`api/barcode_image.php?barcode=${barcode}&format=dataurl&width=250&height=100`);
      
      if (barcodeResponse.data && barcodeResponse.data.image) {
        const sourceText = barcodeResponse.data.source === 'database' ? 'Loaded from database' : 'Generated';
        document.getElementById('barcode-image-container').innerHTML = `
          <img src="${barcodeResponse.data.image}" alt="Barcode" class="img-fluid" style="max-width: 250px; border: 1px solid #ddd; border-radius: 4px;">
          <div class="small text-muted mt-1">${sourceText}</div>
        `;
      } else {
        // Fallback: show barcode as text if image generation fails
        document.getElementById('barcode-image-container').innerHTML = `
          <div class="border p-2 bg-light" style="font-family: monospace; font-size: 14px;">
            ${barcode}
          </div>
        `;
      }
      
      // Hide the save button and show close button
      document.getElementById('saveProductBtn').style.display = 'none';
      
      // Change modal title
      document.querySelector('#productModal .modal-title').textContent = 'Product Created Successfully';
      
    } catch (err) {
      console.error('Failed to get barcode image:', err);
      // Show fallback
      document.getElementById('barcode-image-container').innerHTML = `
        <div class="border p-2 bg-light" style="font-family: monospace; font-size: 14px;">
          ${barcode}
        </div>
      `;
    }
  }

  // Save manual product
  async function saveManualProduct() {
    const formData = {
      name: document.getElementById('modal-name').value.trim(),
      description: document.getElementById('modal-description').value.trim(),
      unit_price: parseFloat(document.getElementById('modal-price').value),
      weight_kg: parseFloat(document.getElementById('modal-weight').value) || null,
      reorder_point: parseInt(document.getElementById('modal-reorder-point').value) || 10,
      reorder_quantity: parseInt(document.getElementById('modal-reorder-qty').value) || 50,
      lead_time_days: parseInt(document.getElementById('modal-lead-time').value) || 7,
      warehouse_id: document.getElementById('modal-warehouse').value || null,
      initial_quantity: parseInt(document.getElementById('modal-quantity').value) || 0,
      category_id: document.getElementById('modal-category').value || null,
      location_code: document.getElementById('modal-location').value.trim() || null
    };
    
    // Add SKU and barcode only if provided
    const sku = document.getElementById('modal-sku').value.trim();
    const barcode = document.getElementById('modal-barcode').value.trim();
    if (sku) formData.sku = sku;
    if (barcode) formData.barcode = barcode;
    
    // Add product image if uploaded
    const imageInput = document.getElementById('product-image');
    if (imageInput.files[0]) {
      const base64Image = await fileToBase64(imageInput.files[0]);
      formData.product_image = base64Image;
    }
    
    if (!formData.name || !formData.unit_price) {
      Swal.fire({
        icon: 'warning',
        title: 'Missing Information',
        text: 'Please fill in all required fields (Name, Unit Price)',
        confirmButtonColor: '#0d6efd'
      });
      return;
    }
    
    if (!formData.category_id) {
      Swal.fire({
        icon: 'warning',
        title: 'Category Required',
        text: 'Please select a category or upload an image for AI analysis',
        confirmButtonColor: '#0d6efd'
      });
      return;
    }
    
    // Warn if warehouse not selected but quantity > 0
    if (formData.initial_quantity > 0 && !formData.warehouse_id) {
      const result = await Swal.fire({
        icon: 'warning',
        title: 'No Warehouse Selected',
        text: 'You set an initial quantity but no warehouse. The product will be created without inventory. Continue?',
        showCancelButton: true,
        confirmButtonText: 'Continue Anyway',
        cancelButtonText: 'Go Back',
        confirmButtonColor: '#0d6efd'
      });
      
      if (!result.isConfirmed) {
        return;
      }
    }
    
    try {
      document.getElementById('saveProductBtn').disabled = true;
      document.getElementById('saveProductBtn').innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Saving...';
      
      const response = await Api.send('api/product_create.php', 'POST', formData);
      
      // Close the modal first
      const modal = bootstrap.Modal.getInstance(document.getElementById('productModal'));
      if (modal) modal.hide();
      
      // Show success alert with option to view barcode
      const result = await Swal.fire({
        icon: 'success',
        title: 'Product Created Successfully!',
        html: `
          <div class="text-start">
            <p><strong>${formData.name}</strong> has been added to the catalog.</p>
            <div class="mt-3">
              <p class="mb-2"><strong>SKU:</strong> <code>${response.data.sku}</code></p>
              <p class="mb-2"><strong>Barcode:</strong> <code>${response.data.barcode}</code></p>
              ${formData.location_code ? `<p class="mb-2"><strong>Location:</strong> <code>${formData.location_code}</code></p>` : ''}
            </div>
          </div>
        `,
        showCancelButton: true,
        confirmButtonText: '<i class="fa-solid fa-barcode me-2"></i>View Barcode',
        cancelButtonText: 'Close',
        confirmButtonColor: '#0d6efd',
        cancelButtonColor: '#6c757d',
        reverseButtons: true
      });
      
      // If user wants to view barcode
      if (result.isConfirmed) {
        await showBarcodeModal(response.data.sku, response.data.barcode, formData.name, formData.location_code);
      }
      
      // Refresh products list if on that tab
      if (document.getElementById('products-tab').classList.contains('active')) {
        loadProducts();
      }
      
      // Refresh inventory list if on that tab
      if (document.getElementById('inventory-tab').classList.contains('active')) {
        loadInventory();
      }
      
      // Refresh notifications immediately
      if (typeof loadNotifications === 'function') {
        setTimeout(loadNotifications, 500);
      }
      
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: 'Creation Failed',
        text: 'Failed to create product: ' + err.message,
        confirmButtonColor: '#dc3545'
      });
    } finally {
      document.getElementById('saveProductBtn').disabled = false;
      document.getElementById('saveProductBtn').innerHTML = '<i class="fa-solid fa-save me-1"></i>Save Product';
    }
  }

  // Event listeners
  startBtn.addEventListener('click', startScanning);
  stopBtn.addEventListener('click', stopScanning);
  toggleBtn.addEventListener('click', switchCamera);

  // Cancel add product
  document.getElementById('cancel-add').addEventListener('click', () => {
    quickAddForm.style.display = 'none';
    scanOutput.innerHTML = '<div class="text-muted">No scans yet</div>';
  });

  // Add product form submission
  addProductForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formData = {
      barcode: document.getElementById('scanned-barcode').value,
      name: document.getElementById('product-name').value,
      unit_price: parseFloat(document.getElementById('product-price').value),
      description: document.getElementById('product-description').value,
      warehouse_id: document.getElementById('product-warehouse').value || null,
      initial_quantity: parseInt(document.getElementById('product-quantity').value) || 0
    };
    
    try {
      const response = await Api.send('api/barcode_lookup.php', 'POST', formData);
      
      // Generate barcode image for display
      let barcodeImageHtml = '';
      try {
        const barcodeResponse = await Api.get(`api/barcode_image.php?barcode=${response.data.barcode}&format=dataurl&width=200&height=80`);
        if (barcodeResponse.data && barcodeResponse.data.image) {
          barcodeImageHtml = `<img src="${barcodeResponse.data.image}" alt="Barcode" class="img-fluid mt-2" style="max-width: 200px; border: 1px solid #ddd; border-radius: 4px;">`;
        }
      } catch (imgErr) {
        console.error('Failed to generate barcode image:', imgErr);
      }
      
      scanOutput.innerHTML = `
        <div class="alert alert-success">
          <i class="fa-solid fa-check-circle me-2"></i>
          <strong>Product added successfully!</strong><br>
          <div class="row mt-2">
            <div class="col-8">
              <strong>SKU:</strong> ${response.data.sku}<br>
              <strong>Barcode:</strong> ${response.data.barcode}
            </div>
            <div class="col-4 text-center">
              ${barcodeImageHtml}
            </div>
          </div>
        </div>
      `;
      
      quickAddForm.style.display = 'none';
      
      // Show SweetAlert notification
      Swal.fire({
        icon: 'success',
        title: 'Product Added!',
        text: `${formData.name} has been added to inventory`,
        timer: 3000,
        showConfirmButton: false,
        toast: true,
        position: 'top-end'
      });
      
      // Refresh products list if on that tab
      if (document.getElementById('products-tab').classList.contains('active')) {
        loadProducts();
      }
      
      // Refresh inventory list if on that tab
      if (document.getElementById('inventory-tab').classList.contains('active')) {
        loadInventory();
      }
      
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: 'Failed to Add Product',
        text: err.message,
        confirmButtonColor: '#dc3545'
      });
      showError('Failed to add product: ' + err.message);
    }
  });

  // Product search
  let searchTimeout;
  document.getElementById('product-search').addEventListener('input', (e) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
      loadProducts(e.target.value.trim());
    }, 300);
  });

  // Location editing function
  window.editLocation = async function(inventoryId, currentLocation) {
    const newLocation = prompt('Enter location/bin code:', currentLocation);
    if (newLocation !== null && newLocation !== currentLocation) {
      try {
        await Api.send(`api/inventory.php?id=${inventoryId}`, 'PUT', { location_code: newLocation });
        document.getElementById(`location-${inventoryId}`).textContent = newLocation || 'Not set';
        Swal.fire({
          icon: 'success',
          title: 'Location Updated',
          text: 'Inventory location has been updated',
          timer: 2000,
          showConfirmButton: false,
          toast: true,
          position: 'top-end'
        });
      } catch (err) {
        Swal.fire({
          icon: 'error',
          title: 'Update Failed',
          text: err.message
        });
      }
    }
  };

  // Inventory adjustment function
  window.adjustInventoryForItem = async function(inventoryId, productId, warehouseId) {
    const { value: formValues } = await Swal.fire({
      title: 'Adjust Inventory',
      html: `
        <div class="mb-3">
          <label class="form-label">Adjustment Type</label>
          <select id="adjustment-type" class="form-select">
            <option value="adjustment">Stock Adjustment</option>
            <option value="receipt">Receipt</option>
            <option value="shipment">Shipment</option>
            <option value="return">Return</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Quantity Change</label>
          <input type="number" id="quantity-change" class="form-control" placeholder="Enter positive or negative number">
          <div class="form-text">Use positive numbers to increase stock, negative to decrease</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Notes (optional)</label>
          <textarea id="adjustment-notes" class="form-control" rows="2"></textarea>
        </div>
      `,
      focusConfirm: false,
      showCancelButton: true,
      confirmButtonText: 'Apply Adjustment',
      preConfirm: () => {
        const type = document.getElementById('adjustment-type').value;
        const quantity = parseInt(document.getElementById('quantity-change').value);
        const notes = document.getElementById('adjustment-notes').value;
        
        if (!quantity || quantity === 0) {
          Swal.showValidationMessage('Please enter a valid quantity change');
          return false;
        }
        
        return { type, quantity, notes };
      }
    });

    if (formValues) {
      try {
        await Api.send('api/inventory.php', 'POST', {
          action: 'adjust',
          product_id: productId,
          warehouse_id: warehouseId,
          quantity: formValues.quantity,
          transaction_type: formValues.type,
          notes: formValues.notes
        });
        
        Swal.fire({
          icon: 'success',
          title: 'Inventory Adjusted',
          text: 'Stock levels have been updated',
          timer: 2000,
          showConfirmButton: false
        });
        
        loadInventory(); // Refresh the list
      } catch (err) {
        Swal.fire({
          icon: 'error',
          title: 'Adjustment Failed',
          text: err.message
        });
      }
    }
  };

  // View inventory history function
  window.viewInventoryHistory = async function(productId, warehouseId) {
    try {
      const response = await Api.get(`api/inventory_transactions.php?product_id=${productId}&warehouse_id=${warehouseId}&limit=20`);
      
      if (!response.data.items || response.data.items.length === 0) {
        Swal.fire({
          icon: 'info',
          title: 'No History',
          text: 'No inventory transactions found for this item'
        });
        return;
      }
      
      const historyHtml = response.data.items.map(tx => `
        <tr>
          <td>${new Date(tx.created_at).toLocaleDateString()}</td>
          <td><span class="badge bg-${tx.transaction_type === 'receipt' ? 'success' : tx.transaction_type === 'shipment' ? 'danger' : 'warning'}">${tx.transaction_type}</span></td>
          <td>${tx.quantity > 0 ? '+' : ''}${tx.quantity}</td>
          <td>${tx.notes || '-'}</td>
        </tr>
      `).join('');
      
      Swal.fire({
        title: 'Inventory History',
        html: `
          <div class="table-responsive">
            <table class="table table-sm">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Type</th>
                  <th>Qty Change</th>
                  <th>Notes</th>
                </tr>
              </thead>
              <tbody>${historyHtml}</tbody>
            </table>
          </div>
        `,
        width: '600px',
        showCloseButton: true,
        showConfirmButton: false
      });
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: 'Failed to Load History',
        text: err.message
      });
    }
  };

  // Load warehouses list
  async function loadWarehousesList() {
    try {
      const response = await Api.get('api/warehouses.php?limit=50');
      const container = document.getElementById('warehouses-list');
      
      if (!response.data.items || response.data.items.length === 0) {
        container.innerHTML = '<div class="text-muted text-center py-4">No warehouses found</div>';
        return;
      }
      
      const table = `
        <div class="table-responsive">
          <table class="table table-hover">
            <thead class="table-light">
              <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Address</th>
                <th>Capacity (m³)</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              ${response.data.items.map(warehouse => `
                <tr>
                  <td><code>${warehouse.code}</code></td>
                  <td>${warehouse.name}</td>
                  <td>${warehouse.address || '-'}</td>
                  <td>${warehouse.capacity_cubic_meters || '-'}</td>
                  <td><span class="badge bg-${warehouse.is_active ? 'success' : 'secondary'}">${warehouse.is_active ? 'Active' : 'Inactive'}</span></td>
                  <td>
                    <button class="btn btn-sm btn-outline-primary me-1" onclick="editWarehouse(${warehouse.id})" title="Edit">
                      <i class="fa-solid fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-info" onclick="viewWarehouseInventory(${warehouse.id})" title="View Inventory">
                      <i class="fa-solid fa-boxes-stacked"></i>
                    </button>
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      `;
      
      container.innerHTML = table;
    } catch (err) {
      document.getElementById('warehouses-list').innerHTML = `<div class="alert alert-danger">Failed to load warehouses: ${err.message}</div>`;
    }
  }

  // Add warehouse function
  window.showAddWarehouseModal = async function() {
    const { value: formValues } = await Swal.fire({
      title: 'Add New Warehouse',
      html: `
        <div class="mb-3">
          <label class="form-label">Warehouse Code *</label>
          <input type="text" id="warehouse-code" class="form-control" placeholder="e.g., WH001">
        </div>
        <div class="mb-3">
          <label class="form-label">Warehouse Name *</label>
          <input type="text" id="warehouse-name" class="form-control" placeholder="e.g., Main Warehouse">
        </div>
        <div class="mb-3">
          <label class="form-label">Address</label>
          <textarea id="warehouse-address" class="form-control" rows="2"></textarea>
        </div>
        <div class="row">
          <div class="col-6">
            <label class="form-label">City</label>
            <input type="text" id="warehouse-city" class="form-control">
          </div>
          <div class="col-6">
            <label class="form-label">Country</label>
            <input type="text" id="warehouse-country" class="form-control">
          </div>
        </div>
        <div class="mb-3 mt-3">
          <label class="form-label">Capacity (m³)</label>
          <input type="number" step="0.01" id="warehouse-capacity" class="form-control">
        </div>
      `,
      focusConfirm: false,
      showCancelButton: true,
      confirmButtonText: 'Add Warehouse',
      preConfirm: () => {
        const code = document.getElementById('warehouse-code').value;
        const name = document.getElementById('warehouse-name').value;
        const address = document.getElementById('warehouse-address').value;
        const city = document.getElementById('warehouse-city').value;
        const country = document.getElementById('warehouse-country').value;
        const capacity = parseFloat(document.getElementById('warehouse-capacity').value);
        
        if (!code || !name) {
          Swal.showValidationMessage('Code and Name are required');
          return false;
        }
        
        return { code, name, address, city, country, capacity_cubic_meters: capacity || null };
      }
    });

    if (formValues) {
      try {
        await Api.send('api/warehouses.php', 'POST', formValues);
        
        Swal.fire({
          icon: 'success',
          title: 'Warehouse Added',
          text: 'New warehouse has been created',
          timer: 2000,
          showConfirmButton: false
        });
        
        loadWarehousesList(); // Refresh the list
      } catch (err) {
        Swal.fire({
          icon: 'error',
          title: 'Failed to Add Warehouse',
          text: err.message
        });
      }
    }
  };

  // Tab change handlers
  document.getElementById('inventory-tab').addEventListener('shown.bs.tab', loadInventory);
  document.getElementById('products-tab').addEventListener('shown.bs.tab', () => loadProducts());
  document.getElementById('warehouses-tab').addEventListener('shown.bs.tab', loadWarehousesList);
  
  // View product details function
  window.viewProductDetails = async function(productId) {
    try {
      const response = await Api.get(`api/products.php?id=${productId}`);
      const product = response.data;
      
      Swal.fire({
        title: 'Product Details',
        html: `
          <div class="text-start">
            <div class="row mb-2">
              <div class="col-4"><strong>SKU:</strong></div>
              <div class="col-8"><code>${product.sku}</code></div>
            </div>
            <div class="row mb-2">
              <div class="col-4"><strong>Name:</strong></div>
              <div class="col-8">${product.name}</div>
            </div>
            <div class="row mb-2">
              <div class="col-4"><strong>Description:</strong></div>
              <div class="col-8">${product.description || '-'}</div>
            </div>
            <div class="row mb-2">
              <div class="col-4"><strong>Price:</strong></div>
              <div class="col-8">₱${parseFloat(product.unit_price).toFixed(2)}</div>
            </div>
            <div class="row mb-2">
              <div class="col-4"><strong>Weight:</strong></div>
              <div class="col-8">${product.weight_kg ? product.weight_kg + ' kg' : '-'}</div>
            </div>
            <div class="row mb-2">
              <div class="col-4"><strong>Reorder Point:</strong></div>
              <div class="col-8">${product.reorder_point}</div>
            </div>
            <div class="row mb-2">
              <div class="col-4"><strong>Lead Time:</strong></div>
              <div class="col-8">${product.lead_time_days} days</div>
            </div>
            <div class="row mb-2">
              <div class="col-4"><strong>Barcode:</strong></div>
              <div class="col-8">
                ${product.barcode ? 
                  `<div>
                    <code>${product.barcode}</code>
                    <button class="btn btn-sm btn-outline-primary ms-2" onclick="showBarcodeImage('${product.barcode}')">
                      <i class="fa-solid fa-barcode"></i> View Image
                    </button>
                  </div>` : 
                  '-'
                }
              </div>
            </div>
            <div class="row mb-2">
              <div class="col-4"><strong>Status:</strong></div>
              <div class="col-8">
                <span class="badge bg-${product.is_active ? 'success' : 'secondary'}">
                  ${product.is_active ? 'Active' : 'Inactive'}
                </span>
              </div>
            </div>
            <div class="row mb-2">
              <div class="col-4"><strong>Created:</strong></div>
              <div class="col-8">${new Date(product.created_at).toLocaleDateString()}</div>
            </div>
          </div>
        `,
        width: '600px',
        showCloseButton: true,
        showConfirmButton: false,
        footer: `
          <button class="btn btn-primary me-2" onclick="editProduct(${productId})">
            <i class="fa-solid fa-edit me-1"></i>Edit Product
          </button>
          <button class="btn btn-outline-info" onclick="viewProductInventory(${productId})">
            <i class="fa-solid fa-boxes-stacked me-1"></i>View Inventory
          </button>
        `
      });
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: 'Failed to Load Product',
        text: err.message
      });
    }
  };

  // Show barcode image function
  window.showBarcodeImage = async function(barcode) {
    try {
      const response = await Api.get(`api/barcode_image.php?barcode=${barcode}&format=dataurl&width=300&height=120`);
      
      if (response.data && response.data.image) {
        Swal.fire({
          title: 'Barcode Image',
          html: `
            <div class="text-center">
              <img src="${response.data.image}" alt="Barcode" class="img-fluid" style="max-width: 300px; border: 1px solid #ddd; border-radius: 4px;">
              <div class="mt-2">
                <code>${barcode}</code>
              </div>
              <div class="small text-muted mt-1">
                ${response.data.source === 'database' ? 'Loaded from database' : 'Generated on demand'}
              </div>
            </div>
          `,
          showCloseButton: true,
          showConfirmButton: false
        });
      }
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: 'Failed to Load Barcode',
        text: err.message
      });
    }
  };

  // Edit product function
  window.editProduct = async function(productId) {
    try {
      const response = await Api.get(`api/products.php?id=${productId}`);
      const product = response.data;
      
      const { value: formValues } = await Swal.fire({
        title: 'Edit Product',
        html: `
          <div class="mb-3">
            <label class="form-label">Product Name *</label>
            <input type="text" id="edit-name" class="form-control" value="${product.name}">
          </div>
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea id="edit-description" class="form-control" rows="2">${product.description || ''}</textarea>
          </div>
          <div class="row">
            <div class="col-6">
              <label class="form-label">Unit Price *</label>
              <input type="number" step="0.01" id="edit-price" class="form-control" value="${product.unit_price}">
            </div>
            <div class="col-6">
              <label class="form-label">Weight (kg)</label>
              <input type="number" step="0.001" id="edit-weight" class="form-control" value="${product.weight_kg || ''}">
            </div>
          </div>
          <div class="row mt-3">
            <div class="col-6">
              <label class="form-label">Reorder Point</label>
              <input type="number" id="edit-reorder-point" class="form-control" value="${product.reorder_point}">
            </div>
            <div class="col-6">
              <label class="form-label">Lead Time (days)</label>
              <input type="number" id="edit-lead-time" class="form-control" value="${product.lead_time_days}">
            </div>
          </div>
          <div class="mt-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="edit-active" ${product.is_active ? 'checked' : ''}>
              <label class="form-check-label" for="edit-active">
                Active
              </label>
            </div>
          </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Update Product',
        preConfirm: () => {
          const name = document.getElementById('edit-name').value;
          const description = document.getElementById('edit-description').value;
          const price = parseFloat(document.getElementById('edit-price').value);
          const weight = parseFloat(document.getElementById('edit-weight').value);
          const reorderPoint = parseInt(document.getElementById('edit-reorder-point').value);
          const leadTime = parseInt(document.getElementById('edit-lead-time').value);
          const isActive = document.getElementById('edit-active').checked;
          
          if (!name || !price) {
            Swal.showValidationMessage('Name and Price are required');
            return false;
          }
          
          return {
            name,
            description,
            unit_price: price,
            weight_kg: weight || null,
            reorder_point: reorderPoint,
            lead_time_days: leadTime,
            is_active: isActive
          };
        }
      });

      if (formValues) {
        await Api.send(`api/products.php?id=${productId}`, 'PUT', formValues);
        
        Swal.fire({
          icon: 'success',
          title: 'Product Updated',
          text: 'Product has been updated successfully',
          timer: 2000,
          showConfirmButton: false
        });
        
        loadProducts(); // Refresh the list
      }
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: 'Failed to Edit Product',
        text: err.message
      });
    }
  };

  // View product inventory function
  window.viewProductInventory = async function(productId) {
    try {
      const response = await Api.get(`api/inventory.php?product_id=${productId}&limit=50`);
      
      if (!response.data.items || response.data.items.length === 0) {
        Swal.fire({
          icon: 'info',
          title: 'No Inventory',
          text: 'No inventory records found for this product'
        });
        return;
      }
      
      const inventoryHtml = response.data.items.map(item => `
        <tr>
          <td>${item.warehouse_name}</td>
          <td><span class="badge bg-primary">${item.quantity}</span></td>
          <td><span class="badge bg-success">${item.quantity - (item.reserved_quantity || 0)}</span></td>
          <td>${item.location_code || 'Not set'}</td>
          <td>${new Date(item.updated_at).toLocaleDateString()}</td>
        </tr>
      `).join('');
      
      Swal.fire({
        title: 'Product Inventory',
        html: `
          <div class="table-responsive">
            <table class="table table-sm">
              <thead>
                <tr>
                  <th>Warehouse</th>
                  <th>Total Qty</th>
                  <th>Available</th>
                  <th>Location</th>
                  <th>Last Updated</th>
                </tr>
              </thead>
              <tbody>${inventoryHtml}</tbody>
            </table>
          </div>
        `,
        width: '800px',
        showCloseButton: true,
        showConfirmButton: false
      });
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: 'Failed to Load Inventory',
        text: err.message
      });
    }
  };

  // Edit warehouse function
  window.editWarehouse = async function(warehouseId) {
    try {
      const response = await Api.get(`api/warehouses.php?id=${warehouseId}`);
      const warehouse = response.data;
      
      const { value: formValues } = await Swal.fire({
        title: 'Edit Warehouse',
        html: `
          <div class="mb-3">
            <label class="form-label">Warehouse Code *</label>
            <input type="text" id="warehouse-code" class="form-control" value="${warehouse.code}">
          </div>
          <div class="mb-3">
            <label class="form-label">Warehouse Name *</label>
            <input type="text" id="warehouse-name" class="form-control" value="${warehouse.name}">
          </div>
          <div class="mb-3">
            <label class="form-label">Address</label>
            <textarea id="warehouse-address" class="form-control" rows="2">${warehouse.address || ''}</textarea>
          </div>
          <div class="row">
            <div class="col-6">
              <label class="form-label">City</label>
              <input type="text" id="warehouse-city" class="form-control" value="${warehouse.city || ''}">
            </div>
            <div class="col-6">
              <label class="form-label">Country</label>
              <input type="text" id="warehouse-country" class="form-control" value="${warehouse.country || ''}">
            </div>
          </div>
          <div class="mb-3 mt-3">
            <label class="form-label">Capacity (m³)</label>
            <input type="number" step="0.01" id="warehouse-capacity" class="form-control" value="${warehouse.capacity_cubic_meters || ''}">
          </div>
          <div class="mb-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="warehouse-active" ${warehouse.is_active ? 'checked' : ''}>
              <label class="form-check-label" for="warehouse-active">
                Active
              </label>
            </div>
          </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Update Warehouse',
        preConfirm: () => {
          const code = document.getElementById('warehouse-code').value;
          const name = document.getElementById('warehouse-name').value;
          const address = document.getElementById('warehouse-address').value;
          const city = document.getElementById('warehouse-city').value;
          const country = document.getElementById('warehouse-country').value;
          const capacity = parseFloat(document.getElementById('warehouse-capacity').value);
          const isActive = document.getElementById('warehouse-active').checked;
          
          if (!code || !name) {
            Swal.showValidationMessage('Code and Name are required');
            return false;
          }
          
          return { 
            code, 
            name, 
            address, 
            city, 
            country, 
            capacity_cubic_meters: capacity || null,
            is_active: isActive
          };
        }
      });

      if (formValues) {
        await Api.send(`api/warehouses.php?id=${warehouseId}`, 'PUT', formValues);
        
        Swal.fire({
          icon: 'success',
          title: 'Warehouse Updated',
          text: 'Warehouse has been updated successfully',
          timer: 2000,
          showConfirmButton: false
        });
        
        loadWarehousesList(); // Refresh the list
      }
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: 'Failed to Edit Warehouse',
        text: err.message
      });
    }
  };

  // View warehouse inventory function
  window.viewWarehouseInventory = async function(warehouseId) {
    try {
      const response = await Api.get(`api/inventory.php?warehouse_id=${warehouseId}&limit=50`);
      
      if (!response.data.items || response.data.items.length === 0) {
        Swal.fire({
          icon: 'info',
          title: 'No Inventory',
          text: 'No inventory items found in this warehouse'
        });
        return;
      }
      
      const inventoryHtml = response.data.items.map(item => `
        <tr>
          <td><code>${item.sku}</code></td>
          <td>${item.product_name}</td>
          <td><span class="badge bg-primary">${item.quantity}</span></td>
          <td><span class="badge bg-success">${item.quantity - (item.reserved_quantity || 0)}</span></td>
          <td>${item.location_code || 'Not set'}</td>
        </tr>
      `).join('');
      
      Swal.fire({
        title: 'Warehouse Inventory',
        html: `
          <div class="table-responsive">
            <table class="table table-sm">
              <thead>
                <tr>
                  <th>SKU</th>
                  <th>Product</th>
                  <th>Total Qty</th>
                  <th>Available</th>
                  <th>Location</th>
                </tr>
              </thead>
              <tbody>${inventoryHtml}</tbody>
            </table>
          </div>
        `,
        width: '700px',
        showCloseButton: true,
        showConfirmButton: false
      });
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: 'Failed to Load Inventory',
        text: err.message
      });
    }
  };

  // Delete product function
  window.deleteProduct = async function(productId, productName, sku) {
    const result = await Swal.fire({
      title: 'Delete Product?',
      html: `
        <p>Are you sure you want to delete <strong>${productName}</strong> (SKU: ${sku})?</p>
        <p class="text-warning"><i class="fa-solid fa-exclamation-triangle me-2"></i>This will:</p>
        <ul class="text-start">
          <li>Remove the product from the catalog</li>
          <li>Delete all inventory records</li>
          <li>Archive the data for potential restoration</li>
        </ul>
        <div class="mt-3">
          <label class="form-label text-start d-block">Reason for deletion (optional):</label>
          <textarea id="deletion-reason" class="form-control" rows="2" placeholder="e.g., Discontinued, Duplicate entry, etc."></textarea>
        </div>
      `,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#dc3545',
      cancelButtonColor: '#6c757d',
      confirmButtonText: 'Yes, Delete It',
      cancelButtonText: 'Cancel',
      preConfirm: () => {
        return document.getElementById('deletion-reason').value;
      }
    });
    
    if (result.isConfirmed) {
      try {
        const reason = result.value || 'No reason provided';
        
        const response = await Api.send(`api/product_delete.php?id=${productId}`, 'DELETE', {
          reason: reason
        });
        
        await Swal.fire({
          icon: 'success',
          title: 'Product Deleted',
          html: `
            <p><strong>${response.data.product_name}</strong> has been deleted and archived.</p>
            <p class="text-muted small">Archive ID: ${response.data.archive_id}</p>
            <p class="text-muted small">Inventory records deleted: ${response.data.inventory_records_deleted}</p>
          `,
          timer: 3000,
          showConfirmButton: true
        });
        
        // Refresh the products list
        loadProducts();
        
        // Refresh inventory if on that tab
        if (document.getElementById('inventory-tab').classList.contains('active')) {
          loadInventory();
        }
        
        // Refresh notifications
        if (typeof loadNotifications === 'function') {
          setTimeout(loadNotifications, 500);
        }
        
      } catch (err) {
        const isPOConstraint = err.message.includes('purchase order');
        Swal.fire({
          icon: 'error',
          title: 'Deletion Failed',
          html: isPOConstraint 
            ? `<p>${err.message}</p><p class="mt-3"><strong>Suggestion:</strong> You can mark this product as inactive instead of deleting it.</p>`
            : err.message
        });
      }
    }
  };

  // Load archived products
  let currentArchivePage = 1;
  const archivePerPage = 15;
  
  window.loadArchivedProducts = async function(search = '', page = 1) {
    try {
      currentArchivePage = page;
      const showRestored = document.getElementById('show-restored').checked;
      const url = `api/archived_products.php?page=${page}&limit=${archivePerPage}${search ? `&q=${encodeURIComponent(search)}` : ''}${showRestored ? '&show_restored=true' : ''}`;
      const response = await Api.get(url);
      const container = document.getElementById('archive-list');
      
      if (response.data.items.length === 0) {
        container.innerHTML = '<div class="text-muted">No archived products found</div>';
        return;
      }
      
      const rows = response.data.items.map(item => {
        const statusBadge = item.is_restored 
          ? `<span class="badge bg-success">Restored</span>`
          : `<span class="badge bg-secondary">Archived</span>`;
        
        const restoreBtn = !item.is_restored 
          ? `<button class="btn btn-sm btn-outline-success me-1" onclick="restoreProduct(${item.id}, '${item.name.replace(/'/g, "\\'")}', '${item.sku}')" title="Restore Product">
               <i class="fa-solid fa-undo"></i>
             </button>`
          : '';
        
        return `
          <tr>
            <td>${item.sku}</td>
            <td>${item.name}</td>
            <td>${item.category_name || '-'}</td>
            <td>₱${parseFloat(item.unit_price).toFixed(2)}</td>
            <td>${statusBadge}</td>
            <td><small class="text-muted">${new Date(item.deleted_at).toLocaleDateString()}</small></td>
            <td><small class="text-muted">${item.deleted_by_name}</small></td>
            <td>
              ${restoreBtn}
              <button class="btn btn-sm btn-outline-info" onclick="viewArchivedDetails(${item.id})" title="View Details">
                <i class="fa-solid fa-eye"></i>
              </button>
            </td>
          </tr>
        `;
      }).join('');
      
      // Pagination
      const totalPages = Math.ceil(response.data.total / archivePerPage);
      let paginationHtml = '';
      
      if (totalPages > 1) {
        paginationHtml = '<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">';
        
        paginationHtml += `<li class="page-item ${currentArchivePage === 1 ? 'disabled' : ''}">
          <a class="page-link" href="#" onclick="loadArchivedProducts('', ${currentArchivePage - 1}); return false;">Previous</a>
        </li>`;
        
        for (let i = 1; i <= totalPages; i++) {
          if (i === 1 || i === totalPages || (i >= currentArchivePage - 2 && i <= currentArchivePage + 2)) {
            paginationHtml += `<li class="page-item ${i === currentArchivePage ? 'active' : ''}">
              <a class="page-link" href="#" onclick="loadArchivedProducts('', ${i}); return false;">${i}</a>
            </li>`;
          } else if (i === currentArchivePage - 3 || i === currentArchivePage + 3) {
            paginationHtml += '<li class="page-item disabled"><span class="page-link">...</span></li>';
          }
        }
        
        paginationHtml += `<li class="page-item ${currentArchivePage === totalPages ? 'disabled' : ''}">
          <a class="page-link" href="#" onclick="loadArchivedProducts('', ${currentArchivePage + 1}); return false;">Next</a>
        </li>`;
        
        paginationHtml += '</ul></nav>';
      }
      
      container.innerHTML = `
        <div class="table-responsive">
          <table class="table table-sm table-hover">
            <thead>
              <tr>
                <th>SKU</th>
                <th>Name</th>
                <th>Category</th>
                <th>Price</th>
                <th>Status</th>
                <th>Deleted At</th>
                <th>Deleted By</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        ${paginationHtml}
        <div class="text-muted small text-center mt-2">
          Showing ${((currentArchivePage - 1) * archivePerPage) + 1} to ${Math.min(currentArchivePage * archivePerPage, response.data.total)} of ${response.data.total} items
        </div>
      `;
    } catch (err) {
      document.getElementById('archive-list').innerHTML = `<div class="alert alert-danger">Failed to load archive: ${err.message}</div>`;
    }
  };

  // View archived product details
  window.viewArchivedDetails = async function(archiveId) {
    try {
      const response = await Api.get(`api/archived_products.php?id=${archiveId}`);
      const item = response.data;
      const inventoryData = item.inventory_data || [];
      
      const inventoryHtml = inventoryData.length > 0 
        ? `
          <h6 class="mt-3">Inventory at Time of Deletion:</h6>
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Warehouse</th>
                <th>Quantity</th>
                <th>Location</th>
              </tr>
            </thead>
            <tbody>
              ${inventoryData.map(inv => `
                <tr>
                  <td>${inv.warehouse_name}</td>
                  <td><span class="badge bg-primary">${inv.quantity}</span></td>
                  <td>${inv.location_code || 'Not set'}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        `
        : '<p class="text-muted">No inventory records</p>';
      
      Swal.fire({
        title: 'Archived Product Details',
        html: `
          <div class="text-start">
            <p><strong>SKU:</strong> ${item.sku}</p>
            <p><strong>Name:</strong> ${item.name}</p>
            <p><strong>Category:</strong> ${item.category_name || '-'}</p>
            <p><strong>Price:</strong> ₱${parseFloat(item.unit_price).toFixed(2)}</p>
            <p><strong>Description:</strong> ${item.description || '-'}</p>
            <p><strong>Barcode:</strong> ${item.barcode || '-'}</p>
            <hr>
            <p><strong>Deleted By:</strong> ${item.deleted_by_name}</p>
            <p><strong>Deleted At:</strong> ${new Date(item.deleted_at).toLocaleString()}</p>
            <p><strong>Reason:</strong> ${item.deletion_reason || 'No reason provided'}</p>
            ${inventoryHtml}
            ${item.is_restored ? `
              <hr>
              <p class="text-success"><i class="fa-solid fa-check-circle me-2"></i>This product was restored on ${new Date(item.restored_at).toLocaleString()}</p>
            ` : ''}
          </div>
        `,
        width: '700px',
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

  // Restore product
  window.restoreProduct = async function(archiveId, productName, sku) {
    const result = await Swal.fire({
      title: 'Restore Product?',
      html: `
        <p>Restore <strong>${productName}</strong> (SKU: ${sku}) from archive?</p>
        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" id="restore-inventory" checked>
          <label class="form-check-label" for="restore-inventory">
            Also restore inventory records
          </label>
        </div>
      `,
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#28a745',
      cancelButtonColor: '#6c757d',
      confirmButtonText: 'Yes, Restore It',
      cancelButtonText: 'Cancel',
      preConfirm: () => {
        return document.getElementById('restore-inventory').checked;
      }
    });
    
    if (result.isConfirmed) {
      try {
        const restoreInventory = result.value;
        
        const response = await Api.send('api/archived_products.php', 'POST', {
          archive_id: archiveId,
          restore_inventory: restoreInventory
        });
        
        await Swal.fire({
          icon: 'success',
          title: 'Product Restored',
          html: `
            <p><strong>${response.data.product_name}</strong> has been restored!</p>
            <p class="text-muted small">New Product ID: ${response.data.product_id}</p>
            <p class="text-muted small">Inventory records restored: ${response.data.inventory_restored}</p>
          `,
          timer: 3000,
          showConfirmButton: true
        });
        
        // Refresh archive list
        loadArchivedProducts();
        
        // Refresh products and inventory if on those tabs
        if (document.getElementById('products-tab').classList.contains('active')) {
          loadProducts();
        }
        if (document.getElementById('inventory-tab').classList.contains('active')) {
          loadInventory();
        }
        
        // Refresh notifications
        if (typeof loadNotifications === 'function') {
          setTimeout(loadNotifications, 500);
        }
        
      } catch (err) {
        Swal.fire({
          icon: 'error',
          title: 'Restoration Failed',
          text: err.message
        });
      }
    }
  };

  // Add event listeners for buttons
  document.getElementById('add-warehouse-btn').addEventListener('click', showAddWarehouseModal);
  document.getElementById('adjust-inventory-btn').addEventListener('click', () => {
    Swal.fire({
      icon: 'info',
      title: 'Adjust Inventory',
      text: 'Click the adjustment button next to any inventory item to modify its quantity',
      confirmButtonText: 'Got it'
    });
  });
  
  // Archive search
  let archiveSearchTimeout;
  document.getElementById('archive-search').addEventListener('input', (e) => {
    clearTimeout(archiveSearchTimeout);
    archiveSearchTimeout = setTimeout(() => {
      loadArchivedProducts(e.target.value);
    }, 300);
  });
  
  // Load archive when tab is shown
  document.getElementById('archive-tab').addEventListener('shown.bs.tab', () => {
    loadArchivedProducts();
  });

  // Add Product button handler
  document.getElementById('add-product-btn').addEventListener('click', showProductModal);

  // Initialize on page load
  document.addEventListener('DOMContentLoaded', async () => {
    await initScanner();
    await loadWarehouses();
    
    // Load initial tab content
    if (document.getElementById('scanner-tab').classList.contains('active')) {
      // Scanner tab is active by default, no need to load data
    }
  });

  // Future-proofing for RFID
  window.SWS = {
    // RFID integration placeholder
    initRFID: function() {
      console.log('RFID integration ready for future implementation');
      // This function will be implemented when RFID hardware is available
    },
    
    // Barcode scanner instance for external access
    getScanner: function() {
      return codeReader;
    },
    
    // Manual barcode input for testing
    simulateBarcodeScan: function(barcode) {
      handleBarcodeResult(barcode);
    }
  };
})();
