window.SWS = window.SWS || {};
// --- Phase X: Shipments Tab Logic ---
// Use SweetAlert2 for notifications
function showAlert(message, type = 'info') {
	if (window.Swal) {
		let icon = type === 'danger' ? 'error' : (type === 'success' ? 'success' : (type === 'warning' ? 'warning' : 'info'));
		Swal.fire({
			icon: icon,
			title: message,
			timer: 2500,
			showConfirmButton: false,
			toast: true,
			position: 'top-end',
			timerProgressBar: true
		});
	} else {
		alert(message);
	}
}
SWS.loadShipments = async function() {
		try {
			// Show all non-archived POs
			const response = await Api.get('api/purchase_orders.php');
			const shipments = response.data.items || [];
			const tbody = document.getElementById('shipmentsTable');
			if (!tbody) return;
			// Debug: show raw API response for troubleshooting
			if (shipments.length === 0) {
				tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No incoming shipments found</td></tr>';
				return;
			}
			tbody.innerHTML = shipments.map(po => `
				<tr>
					<td><strong>${po.po_number}</strong></td>
					<td>${po.supplier_name}</td>
					<td><span class="badge bg-info" id="status-badge-${po.id}">${po.status}</span></td>
					<td>${po.warehouse_name}</td>
					<td>${po.order_date || '-'}</td>
					<td>${po.expected_delivery_date || '-'}</td>
					<td>₱${parseFloat(po.total_amount || 0).toFixed(2)}</td>
					<td class="d-flex gap-1">
						<button class="btn btn-outline-primary btn-sm" onclick="SWS.viewShipmentDetails(${po.id})">
							<i class="fa-solid fa-eye"></i> View
						</button>
						${po.status === 'sent' ? `<button class="btn btn-success btn-sm" id="receive-btn-${po.id}" onclick="SWS.receiveShipment(${po.id}, this)"><i class='fa-solid fa-check'></i> Mark as Received</button>` : ''}
					</td>
				</tr>
			`).join('');
		} catch (error) {
			console.error('Error loading shipments:', error);
			showAlert('Failed to load shipments', 'danger');
		}
	};
SWS.receiveShipment = async function(poId) {
    const btn = arguments[1];
    try {
        // 1. Get PO details to get items
        const poResponse = await Api.get('api/purchase_orders.php?id=' + encodeURIComponent(poId));
        if (!poResponse.ok || !poResponse.data || !poResponse.data.items) {
            throw new Error('Failed to fetch PO details');
        }

        const result = await Swal.fire({
            title: 'Mark as Received?',
            html: `
                This will:<br>
                - Update PO status to received<br>
                - Move items to inventory<br>
                - Update connected modules
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, receive it',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#28a745'
        });

        if (!result.isConfirmed) return;

        if (btn) btn.disabled = true;

        // 2. Prepare items for receiving
        const items = poResponse.data.items.map(item => ({
            item_id: item.id,
            qty: item.quantity,
            performed_by: 1 // TODO: Get actual user ID
        }));

        // 3. Update PO status and move items to inventory
        const updateResp = await Api.send('api/purchase_orders.php?id=' + encodeURIComponent(poId), 'PUT', {
            receive: items,
            status: 'received'
        });

        if (!updateResp.ok) throw new Error(updateResp.error || 'Failed to update PO status');

        // 4. Try to update status in PSM and Supplier Portal, but don't fail if they're not available
        try {
            await Promise.all([
                Api.send('api/psm.php', 'PUT', {
                    po_id: poId,
                    status: 'received'
                }).catch(() => console.log('PSM update skipped - endpoint not available')),
                Api.send('api/supplier_portal.php', 'PUT', {
                    po_id: poId,
                    status: 'received'
                }).catch(() => console.log('Supplier Portal update skipped - endpoint not available'))
            ]);
        } catch (e) {
            // Log but don't fail if updates to other modules fail
            console.warn('Failed to update some connected modules:', e);
        }

        // 5. Update status badge in UI
        const badge = document.getElementById('status-badge-' + poId);
        if (badge) {
            badge.textContent = 'received';
            badge.classList.remove('bg-info');
            badge.classList.add('bg-success');
        }
        if (btn) btn.remove();

        await Swal.fire({
            icon: 'success',
            title: 'Received!',
            text: 'Shipment has been marked as received.',
            toast: true,
            position: 'top-end',
            timer: 3000,
            timerProgressBar: true,
            showConfirmButton: false
        });

        // Refresh inventory if available
        if (typeof loadInventory === 'function') loadInventory();
    } catch (error) {
        console.error('Error receiving shipment:', error);
        await Swal.fire({
            icon: 'error',
            title: 'Error',
            text: error.message || 'Failed to receive shipment',
            showConfirmButton: true
        });
		if (btn) btn.disabled = false;
	}
};


SWS.viewShipmentDetails = async function(poId) {
	try {
		// Fetch PO details from API
		const response = await Api.get('api/purchase_orders.php?id=' + encodeURIComponent(poId));
		const po = response.data ? response.data : null;
		if (!po) {
			showAlert('Shipment details not found.', 'danger');
			return;
		}
		// Build modal HTML
		let html = `<div class="modal fade" id="shipmentDetailsModal" tabindex="-1" aria-labelledby="shipmentDetailsLabel" aria-hidden="true">
			<div class="modal-dialog modal-lg">
				<div class="modal-content">
					<div class="modal-header">
						<h5 class="modal-title" id="shipmentDetailsLabel">Shipment / PO Details</h5>
						<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					</div>
					<div class="modal-body">
						<table class="table table-bordered mb-3">
							<tr><th>PO Number</th><td>${po.po_number}</td></tr>
							<tr><th>Supplier</th><td>${po.supplier_name}</td></tr>
							<tr><th>Status</th><td><span class="badge bg-info">${po.status}</span></td></tr>
							<tr><th>Warehouse</th><td>${po.warehouse_name}</td></tr>
							<tr><th>Order Date</th><td>${po.order_date || '-'}</td></tr>
							<tr><th>Expected Delivery</th><td>${po.expected_delivery_date || '-'}</td></tr>
							<tr><th>Total Amount</th><td>₱${parseFloat(po.total_amount || 0).toFixed(2)}</td></tr>
						</table>
						<h6>Items</h6>
						<div style="max-height:200px;overflow:auto;">
						<table class="table table-sm table-striped">
							<thead><tr><th>Product</th><th>Qty</th><th>Unit</th><th>Price</th><th>Subtotal</th></tr></thead>
							<tbody>
							${(po.items||[]).map(item => `
								<tr>
									<td>${item.product_name || item.product_id}</td>
									<td>${item.quantity}</td>
									<td>${item.unit || ''}</td>
									<td>₱${parseFloat(item.unit_price || 0).toFixed(2)}</td>
									<td>₱${parseFloat(item.subtotal || 0).toFixed(2)}</td>
								</tr>
							`).join('')}
							</tbody>
						</table>
						</div>
					</div>
				</div>
			</div>
		</div>`;
		// Remove any existing modal
		const oldModal = document.getElementById('shipmentDetailsModal');
		if (oldModal) oldModal.remove();
		// Append modal to body and show
		document.body.insertAdjacentHTML('beforeend', html);
		const modal = new bootstrap.Modal(document.getElementById('shipmentDetailsModal'));
		modal.show();
	} catch (e) {
		showAlert('Failed to load shipment details.', 'danger');
	}
};

// Auto-load shipments when Shipments tab is shown
document.addEventListener('DOMContentLoaded', function() {
	const tab = document.getElementById('shipments-tab');
	if (tab) {
		tab.addEventListener('shown.bs.tab', function () {
			SWS.loadShipments();
		});
	}
});
// SWS custom logic (extend or override as needed)
// Notifications and app logic are handled by common.js and app.js

window.SWS = window.SWS || {};

// --- Phase 1: Location Management ---
// --- Phase 1: Location Management (Functional) ---
SWS.addZone = async function(zoneData) {
	try {
		const response = await Api.send('api/warehouse_locations.php?type=zones', 'POST', zoneData);
		showAlert('Zone added successfully!', 'success');
		if (typeof loadLocationTree === 'function') loadLocationTree();
		return response;
	} catch (error) {
		showAlert('Failed to add zone: ' + error.message, 'danger');
	}
};

SWS.addAisle = async function(aisleData) {
	try {
		const response = await Api.send('api/warehouse_locations.php?type=aisles', 'POST', aisleData);
		showAlert('Aisle added successfully!', 'success');
		if (typeof loadLocationTree === 'function') loadLocationTree();
		return response;
	} catch (error) {
		showAlert('Failed to add aisle: ' + error.message, 'danger');
	}
};

SWS.addRack = async function(rackData) {
	try {
		const response = await Api.send('api/warehouse_locations.php?type=racks', 'POST', rackData);
		showAlert('Rack added successfully!', 'success');
		if (typeof loadLocationTree === 'function') loadLocationTree();
		return response;
	} catch (error) {
		showAlert('Failed to add rack: ' + error.message, 'danger');
	}
};

SWS.addBin = async function(binData) {
	try {
		const response = await Api.send('api/warehouse_locations.php?type=bins', 'POST', binData);
		showAlert('Bin added successfully!', 'success');
		if (typeof loadLocationTree === 'function') loadLocationTree();
		return response;
	} catch (error) {
		showAlert('Failed to add bin: ' + error.message, 'danger');
	}
};

SWS.assignProductToLocation = async function(productId, binId, qty) {
	try {
		const data = { product_id: productId, bin_id: binId, qty: qty };
		const response = await Api.send('api/warehouse_locations.php?type=assign', 'POST', data);
		showAlert('Product assigned to location!', 'success');
		if (typeof loadInventory === 'function') loadInventory();
		return response;
	} catch (error) {
		showAlert('Failed to assign product: ' + error.message, 'danger');
	}
};

SWS.manageLocationCapacity = async function(locationId, newCapacity) {
	try {
		const data = { location_id: locationId, capacity: newCapacity };
		const response = await Api.send('api/warehouse_locations.php?type=capacity', 'POST', data);
		showAlert('Location capacity updated!', 'info');
		if (typeof loadLocationTree === 'function') loadLocationTree();
		return response;
	} catch (error) {
		showAlert('Failed to update capacity: ' + error.message, 'danger');
	}
};

SWS.searchLocations = async function(query) {
	try {
		const response = await Api.get('api/warehouse_locations.php?type=search&q=' + encodeURIComponent(query));
		showAlert('Location search complete', 'info');
		return response.data.items || [];
	} catch (error) {
		showAlert('Search failed: ' + error.message, 'danger');
		return [];
	}
};

// --- Phase 2: Barcode System ---
// --- Phase 2: Barcode System (Functional) ---
SWS.generateBarcode = async function(barcode, format = 'svg', width = 250, height = 100) {
	try {
		const url = `api/barcode_image.php?barcode=${encodeURIComponent(barcode)}&format=${format}&width=${width}&height=${height}`;
		if (format === 'svg') {
			// Return SVG as string
			const resp = await fetch(url);
			if (!resp.ok) throw new Error('Failed to generate barcode');
			return await resp.text();
		} else if (format === 'dataurl') {
			const resp = await fetch(url);
			const data = await resp.json();
			if (!data.ok) throw new Error('Failed to generate barcode');
			return data.data.image;
		}
	} catch (error) {
		showAlert('Barcode generation failed: ' + error.message, 'danger');
		return '';
	}
};

SWS.printBarcode = async function(barcode) {
	try {
		const svg = await SWS.generateBarcode(barcode, 'svg');
		const printWindow = window.open('', '_blank');
		printWindow.document.write('<html><head><title>Print Barcode</title></head><body>' + svg + '<script>window.print();<\/script></body></html>');
		printWindow.document.close();
	} catch (error) {
		showAlert('Failed to print barcode: ' + error.message, 'danger');
	}
};

SWS.scanBarcode = async function(barcode) {
	try {
		// Lookup product by barcode
		const resp = await fetch('api/barcode_lookup.php?barcode=' + encodeURIComponent(barcode));
		const data = await resp.json();
		if (!data.ok || !data.data.found) {
			showAlert('Barcode not found', 'warning');
			return null;
		}
		showAlert('Barcode found: ' + data.data.product.name, 'success');
		return data.data.product;
	} catch (error) {
		showAlert('Barcode scan failed: ' + error.message, 'danger');
		return null;
	}
};

SWS.showScanHistory = async function() {
	// This would require a scan log table; for now, show not implemented
	showAlert('Scan history not implemented', 'info');
};

// --- Phase 3: Stock Movements ---
SWS.moveStock = async function(fromBinId, toBinId, productId, qty, reason) {
	try {
		// Out from source bin
		await Api.send('api/inventory_transactions.php', 'POST', {
			product_id: productId,
			warehouse_id: fromBinId, // treat bin as warehouse for now
			type: 'move-out',
			qty: -Math.abs(qty),
			notes: `Moved to bin ${toBinId}. Reason: ${reason}`
		});
		// In to destination bin
		await Api.send('api/inventory_transactions.php', 'POST', {
			product_id: productId,
			warehouse_id: toBinId,
			type: 'move-in',
			qty: Math.abs(qty),
			notes: `Moved from bin ${fromBinId}. Reason: ${reason}`
		});
		showAlert('Stock moved successfully!', 'success');
		if (typeof loadMovements === 'function') loadMovements();
		if (typeof loadInventory === 'function') loadInventory();
	} catch (error) {
		showAlert('Failed to move stock: ' + error.message, 'danger');
	}
};

SWS.adjustStock = async function(binId, productId, qty, reason) {
	try {
		await Api.send('api/inventory_transactions.php', 'POST', {
			product_id: productId,
			warehouse_id: binId,
			type: 'adjust',
			qty: qty,
			notes: reason
		});
		showAlert('Stock adjusted successfully!', 'success');
		if (typeof loadMovements === 'function') loadMovements();
		if (typeof loadInventory === 'function') loadInventory();
	} catch (error) {
		showAlert('Failed to adjust stock: ' + error.message, 'danger');
	}
};

SWS.reportDamage = async function(binId, productId, qty, notes) {
	try {
		await Api.send('api/inventory_transactions.php', 'POST', {
			product_id: productId,
			warehouse_id: binId,
			type: 'damage',
			qty: -Math.abs(qty),
			notes: notes
		});
		showAlert('Damage reported and logged!', 'warning');
		if (typeof loadMovements === 'function') loadMovements();
		if (typeof loadInventory === 'function') loadInventory();
	} catch (error) {
		showAlert('Failed to report damage: ' + error.message, 'danger');
	}
};
SWS.showMovementHistory = async function(filters) {
	showAlert('Movement history (stub)', 'info');
};
SWS.approveMovement = async function(movementId) {
	showAlert('Movement approved (stub)', 'success');
};

// --- Phase 4: Cycle Counting ---
SWS.createCycleCountSession = async function(sessionData) {
	showAlert('Cycle count session created (stub)', 'success');
};
SWS.generateCountSheet = async function(sessionId) {
	showAlert('Count sheet generated (stub)', 'info');
};
SWS.submitCount = async function(sessionId, itemId, countedQty) {
	showAlert('Count submitted (stub)', 'success');
};
SWS.reportVariance = async function(sessionId) {
	showAlert('Variance report (stub)', 'info');
};
SWS.autoAdjustAfterVerification = async function(sessionId) {
	showAlert('Auto-adjustment done (stub)', 'success');
};

// --- Phase 5: PSM Integration ---
SWS.handleGoodsReceipt = async function(receiptId) {
	showAlert('Goods receipt processed (stub)', 'success');
};

// --- Phase 6: Batch/Lot Tracking ---
SWS.registerBatch = async function(batchData) {
	showAlert('Batch registered (stub)', 'success');
};
SWS.expiryAlerts = async function() {
	showAlert('Expiry alerts (stub)', 'warning');
};
SWS.recallBatch = async function(batchId) {
	showAlert('Batch recall (stub)', 'danger');
};
SWS.traceBatch = async function(batchId) {
	showAlert('Batch traceability (stub)', 'info');
};

// --- Phase 7: Pick/Pack/Ship Workflow ---
SWS.createPickingTask = async function(taskData) {
	showAlert('Picking task created (stub)', 'success');
};
SWS.mobilePick = async function(taskId) {
	showAlert('Mobile picking (stub)', 'info');
};
SWS.createPackingTask = async function(packingData) {
	showAlert('Packing task created (stub)', 'success');
};
SWS.generateShippingLabel = async function(shipmentId) {
	showAlert('Shipping label generated (stub)', 'info');
};
SWS.trackShipment = async function(shipmentId) {
	showAlert('Shipment tracking (stub)', 'info');
};

// ============================================
// AI-POWERED WAREHOUSE AUTOMATION
// ============================================

/**
 * Get AI suggestion for optimal storage location
 */
SWS.getAILocationSuggestion = async function(productId, quantity, warehouseId = 1) {
	try {
		const response = await Api.send('api/ai_warehouse.php?action=suggest_location', 'POST', {
			product_id: productId,
			quantity: quantity,
			warehouse_id: warehouseId
		});
		
		if (response.ok && response.data.success) {
			return response.data;
		}
		
		throw new Error(response.data.message || 'Failed to get AI suggestion');
	} catch (error) {
		console.error('AI location suggestion error:', error);
		throw error;
	}
};

/**
 * Auto-assign product to AI-suggested location
 */
SWS.autoAssignLocation = async function(productId, quantity, warehouseId = 1) {
	try {
		const result = await Swal.fire({
			title: 'AI Location Assignment',
			text: 'Let AI find the optimal storage location?',
			icon: 'question',
			showCancelButton: true,
			confirmButtonText: 'Yes, use AI',
			cancelButtonText: 'Cancel'
		});
		
		if (!result.isConfirmed) return;
		
		Swal.fire({
			title: 'AI Processing...',
			text: 'Finding optimal location',
			icon: 'info',
			allowOutsideClick: false,
			didOpen: () => Swal.showLoading()
		});
		
		const response = await Api.send('api/ai_warehouse.php?action=auto_assign_location', 'POST', {
			product_id: productId,
			quantity: quantity,
			warehouse_id: warehouseId
		});
		
		if (response.ok && response.data.success) {
			const location = response.data.location;
			await Swal.fire({
				icon: 'success',
				title: 'Location Assigned!',
				html: `
					<p><strong>Location:</strong> ${location.bin_code}</p>
					<p><strong>Zone:</strong> ${location.zone}</p>
					<p><strong>AI Confidence:</strong> ${(location.confidence * 100).toFixed(0)}%</p>
					<p class="text-muted small">${location.reasoning}</p>
				`,
				showConfirmButton: true
			});
			
			if (typeof loadInventory === 'function') loadInventory();
			return response.data;
		}
		
		throw new Error(response.data.message || 'Failed to assign location');
	} catch (error) {
		Swal.fire({
			icon: 'error',
			title: 'Error',
			text: error.message || 'Failed to assign location'
		});
		throw error;
	}
};

/**
 * Get AI demand prediction for a product
 */
SWS.predictDemand = async function(productId, days = 30) {
	try {
		const response = await Api.send('api/ai_warehouse.php?action=predict_demand', 'POST', {
			product_id: productId,
			days: days
		});
		
		if (response.ok && response.data.success) {
			const prediction = response.data.prediction;
			const daysOfStock = response.data.days_of_stock;
			
			await Swal.fire({
				icon: 'info',
				title: 'AI Demand Forecast',
				html: `
					<div class="text-start">
						<p><strong>Forecast Period:</strong> ${days} days</p>
						<p><strong>Predicted Demand:</strong> ${prediction.predicted_demand} units</p>
						<p><strong>Current Stock:</strong> ${response.data.current_stock} units</p>
						<p><strong>Days of Stock:</strong> ${daysOfStock} days</p>
						<hr>
						<p><strong>Reorder Recommended:</strong> ${prediction.reorder_recommended ? 'Yes' : 'No'}</p>
						${prediction.reorder_recommended ? `<p><strong>Suggested Quantity:</strong> ${prediction.reorder_quantity} units</p>` : ''}
						<p class="text-muted small mt-2">${prediction.reasoning}</p>
						<p class="text-muted small"><strong>AI Confidence:</strong> ${(prediction.confidence * 100).toFixed(0)}%</p>
					</div>
				`,
				width: '600px'
			});
			
			return response.data;
		}
		
		throw new Error(response.data.message || 'Failed to get prediction');
	} catch (error) {
		Swal.fire({
			icon: 'error',
			title: 'Error',
			text: error.message || 'Failed to predict demand'
		});
		throw error;
	}
};

/**
 * Detect inventory anomalies using AI
 */
SWS.detectAnomalies = async function(warehouseId = null) {
	try {
		Swal.fire({
			title: 'AI Analyzing...',
			text: 'Detecting inventory anomalies',
			icon: 'info',
			allowOutsideClick: false,
			didOpen: () => Swal.showLoading()
		});
		
		const response = await Api.send('api/ai_warehouse.php?action=detect_anomalies', 'POST', {
			warehouse_id: warehouseId
		});
		
		if (response.ok && response.data.success) {
			const anomalies = response.data.anomalies || [];
			
			if (anomalies.length === 0) {
				Swal.fire({
					icon: 'success',
					title: 'All Clear!',
					text: 'No anomalies detected in inventory',
					showConfirmButton: true
				});
				return;
			}
			
			let html = '<div class="text-start" style="max-height: 400px; overflow-y: auto;">';
			anomalies.forEach((anomaly, idx) => {
				const severityColor = anomaly.severity === 'high' ? 'danger' : (anomaly.severity === 'medium' ? 'warning' : 'info');
				html += `
					<div class="alert alert-${severityColor} mb-2">
						<strong>${idx + 1}. ${anomaly.sku}</strong>
						<p class="mb-1">${anomaly.issue}</p>
						<small class="text-muted">${anomaly.recommendation}</small>
					</div>
				`;
			});
			html += '</div>';
			
			Swal.fire({
				icon: 'warning',
				title: `${anomalies.length} Anomalies Detected`,
				html: html,
				width: '700px',
				showConfirmButton: true
			});
			
			return response.data;
		}
		
		throw new Error(response.data.message || 'Failed to detect anomalies');
	} catch (error) {
		Swal.fire({
			icon: 'error',
			title: 'Error',
			text: error.message || 'Failed to analyze inventory'
		});
		throw error;
	}
};

// ============================================
// BARCODE SCANNER INTERFACE
// ============================================

/**
 * Open barcode scanner modal
 */
SWS.openBarcodeScanner = function(purpose = 'general') {
	const modalHtml = `
		<div class="modal fade" id="barcodeScannerModal" tabindex="-1">
			<div class="modal-dialog modal-lg">
				<div class="modal-content">
					<div class="modal-header">
						<h5 class="modal-title"><i class="fa-solid fa-barcode me-2"></i>Barcode Scanner</h5>
						<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
					</div>
					<div class="modal-body">
						<div class="mb-3">
							<label class="form-label">Enter or Scan Barcode</label>
							<div class="input-group">
								<input type="text" id="barcodeInput" class="form-control form-control-lg" 
									placeholder="Scan or type barcode..." autofocus>
								<button class="btn btn-primary" onclick="SWS.processBarcodeInput()">
									<i class="fa-solid fa-search"></i> Lookup
								</button>
							</div>
							<small class="text-muted">Supports EAN-13, UPC, and other standard formats</small>
						</div>
						<div id="barcodeScanResult" class="mt-3"></div>
					</div>
				</div>
			</div>
		</div>
	`;
	
	// Remove existing modal
	const existingModal = document.getElementById('barcodeScannerModal');
	if (existingModal) existingModal.remove();
	
	// Add modal to body
	document.body.insertAdjacentHTML('beforeend', modalHtml);
	
	// Show modal
	const modal = new bootstrap.Modal(document.getElementById('barcodeScannerModal'));
	modal.show();
	
	// Add enter key listener
	document.getElementById('barcodeInput').addEventListener('keypress', function(e) {
		if (e.key === 'Enter') {
			SWS.processBarcodeInput();
		}
	});
	
	// Store purpose
	window._barcodeScanPurpose = purpose;
};

/**
 * Process barcode input
 */
SWS.processBarcodeInput = async function() {
	const input = document.getElementById('barcodeInput');
	const barcode = input.value.trim();
	const resultDiv = document.getElementById('barcodeScanResult');
	
	if (!barcode) {
		resultDiv.innerHTML = '<div class="alert alert-warning">Please enter a barcode</div>';
		return;
	}
	
	resultDiv.innerHTML = '<div class="text-center"><div class="spinner-border"></div><p>Looking up barcode...</p></div>';
	
	try {
		// Lookup barcode
		const response = await fetch('api/barcode_lookup.php?barcode=' + encodeURIComponent(barcode));
		const data = await response.json();
		
		if (!data.ok) {
			throw new Error(data.error || 'Lookup failed');
		}
		
		if (data.data.found) {
			const product = data.data.product || data.data.external_product;
			
			// Get AI context
			let aiContext = null;
			try {
				const aiResponse = await Api.send('api/ai_warehouse.php?action=analyze_barcode', 'POST', {
					barcode: barcode,
					scan_purpose: window._barcodeScanPurpose || 'general'
				});
				if (aiResponse.ok && aiResponse.data.success) {
					aiContext = aiResponse.data.context;
				}
			} catch (e) {
				console.warn('AI context failed:', e);
			}
			
			let html = '<div class="card">';
			html += '<div class="card-body">';
			html += `<h5 class="card-title">${product.name}</h5>`;
			html += `<p class="text-muted">SKU: ${product.sku || 'N/A'} | Barcode: ${barcode}</p>`;
			
			if (data.data.inventory && data.data.inventory.length > 0) {
				html += '<h6>Inventory:</h6><ul class="list-group mb-3">';
				data.data.inventory.forEach(inv => {
					html += `<li class="list-group-item d-flex justify-content-between">`;
					html += `<span>${inv.warehouse_name}</span>`;
					html += `<span class="badge bg-primary">${inv.quantity} units</span>`;
					html += `</li>`;
				});
				html += '</ul>';
			}
			
			// AI Context
			if (aiContext) {
				const statusColor = aiContext.status === 'critical' ? 'danger' : (aiContext.status === 'warning' ? 'warning' : 'success');
				html += `<div class="alert alert-${statusColor}">`;
				html += `<strong><i class="fa-solid fa-robot me-2"></i>AI Assistant:</strong><br>`;
				html += `${aiContext.message}`;
				if (aiContext.suggestions && aiContext.suggestions.length > 0) {
					html += '<ul class="mt-2 mb-0">';
					aiContext.suggestions.forEach(s => html += `<li>${s}</li>`);
					html += '</ul>';
				}
				html += '</div>';
			}
			
			html += '<div class="d-flex gap-2">';
			if (product.id) {
				html += `<button class="btn btn-primary" onclick="SWS.autoAssignLocation(${product.id}, 1)">AI Assign Location</button>`;
				html += `<button class="btn btn-info" onclick="SWS.predictDemand(${product.id})">Predict Demand</button>`;
			}
			html += '</div>';
			html += '</div></div>';
			
			resultDiv.innerHTML = html;
		} else {
			resultDiv.innerHTML = `
				<div class="alert alert-warning">
					<strong>Product Not Found</strong><br>
					Barcode ${barcode} is not in the system.
					<button class="btn btn-sm btn-primary mt-2" onclick="SWS.createProductFromBarcode('${barcode}')">
						Create New Product
					</button>
				</div>
			`;
		}
	} catch (error) {
		resultDiv.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
	}
};

/**
 * Create product from barcode
 */
SWS.createProductFromBarcode = async function(barcode) {
	const { value: formValues } = await Swal.fire({
		title: 'Create Product from Barcode',
		html: `
			<input id="swal-name" class="swal2-input" placeholder="Product Name" required>
			<input id="swal-price" class="swal2-input" type="number" step="0.01" placeholder="Unit Price" required>
			<textarea id="swal-desc" class="swal2-textarea" placeholder="Description"></textarea>
		`,
		focusConfirm: false,
		showCancelButton: true,
		preConfirm: () => {
			return {
				name: document.getElementById('swal-name').value,
				price: document.getElementById('swal-price').value,
				description: document.getElementById('swal-desc').value
			};
		}
	});
	
	if (formValues && formValues.name && formValues.price) {
		try {
			const response = await Api.send('api/barcode_lookup.php', 'POST', {
				barcode: barcode,
				name: formValues.name,
				unit_price: formValues.price,
				description: formValues.description
			});
			
			if (response.ok) {
				Swal.fire('Success!', 'Product created successfully', 'success');
				SWS.processBarcodeInput(); // Refresh
			} else {
				throw new Error(response.error || 'Failed to create product');
			}
		} catch (error) {
			Swal.fire('Error', error.message, 'error');
		}
	}
};

 
