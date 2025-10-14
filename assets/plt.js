// PLT (Project & Logistics Tracking) Module JavaScript
const PLT = {
    currentShipment: null,
    
    // Initialize PLT module
    init() {
        this.loadShipments();
        this.bindEvents();
    },
    
    // Load shipments with filters
    async loadShipments(filters = {}) {
        try {
            const params = new URLSearchParams({
                page: filters.page || 1,
                limit: filters.limit || 20,
                status: filters.status || '',
                search: filters.search || ''
            });
            
            const response = await Api.get(`api/plt_shipments.php?${params}`);
            this.renderShipments(response.data.shipments);
            this.updatePagination(response.data.pagination);
        } catch (error) {
            console.error('Error loading shipments:', error);
            this.showError('Failed to load shipments');
        }
    },
    
    // Render shipments table
    renderShipments(shipments) {
        const tbody = document.getElementById('shipmentsTableBody');
        if (!tbody) return;
        
        tbody.innerHTML = shipments.map(shipment => `
            <tr>
                <td><strong>${shipment.tracking_number}</strong></td>
                <td>${shipment.customer_name}</td>
                <td>${this.truncateText(shipment.delivery_address, 30)}</td>
                <td><span class="badge bg-${this.getStatusColor(shipment.status)}">${shipment.status}</span></td>
                <td><span class="badge bg-${this.getPriorityColor(shipment.priority)}">${shipment.priority}</span></td>
                <td>${shipment.estimated_delivery ? new Date(shipment.estimated_delivery).toLocaleDateString() : 'N/A'}</td>
                <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="PLT.viewShipment(${shipment.id})">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-success" onclick="PLT.trackShipment('${shipment.tracking_number}')">
                        <i class="fas fa-map-marker-alt"></i>
                    </button>
                    ${shipment.status === 'out_for_delivery' ? `
                        <button class="btn btn-sm btn-outline-warning" onclick="PLT.confirmDelivery(${shipment.id})">
                            <i class="fas fa-check"></i>
                        </button>
                    ` : ''}
                </td>
            </tr>
        `).join('');
    },
    
    // Create new shipment
    async createShipment(data) {
        try {
            const response = await Api.send('api/plt_shipments.php', 'POST', data);
            this.showSuccess(`Shipment created! Tracking: ${response.data.tracking_number}`);
            this.loadShipments();
            return response.data;
        } catch (error) {
            console.error('Error creating shipment:', error);
            this.showError('Failed to create shipment');
        }
    },
    
    // View shipment details
    async viewShipment(shipmentId) {
        try {
            const response = await Api.get(`api/plt_shipments.php?id=${shipmentId}`);
            this.currentShipment = response.data;
            this.showShipmentModal(response.data);
        } catch (error) {
            console.error('Error loading shipment:', error);
            this.showError('Failed to load shipment details');
        }
    },
    
    // Track shipment (public tracking)
    async trackShipment(trackingNumber) {
        try {
            const response = await Api.get(`api/plt_shipments.php?action=track&tracking_number=${trackingNumber}`);
            this.showTrackingModal(response.data);
        } catch (error) {
            console.error('Error tracking shipment:', error);
            this.showError('Failed to track shipment');
        }
    },
    
    // Update shipment status
    async updateStatus(shipmentId, status, location = '', notes = '') {
        try {
            await Api.send('api/plt_shipments.php?action=update_status', 'PUT', {
                shipment_id: shipmentId,
                status: status,
                location: location,
                notes: notes
            });
            this.showSuccess('Status updated successfully');
            this.loadShipments();
        } catch (error) {
            console.error('Error updating status:', error);
            this.showError('Failed to update status');
        }
    },
    
    // Add tracking event
    async addTrackingEvent(shipmentId, eventData) {
        try {
            await Api.send('api/plt_shipments.php?action=add_event', 'POST', {
                shipment_id: shipmentId,
                ...eventData
            });
            this.showSuccess('Tracking event added');
            if (this.currentShipment && this.currentShipment.id === shipmentId) {
                this.viewShipment(shipmentId); // Refresh details
            }
        } catch (error) {
            console.error('Error adding tracking event:', error);
            this.showError('Failed to add tracking event');
        }
    },
    
    // Confirm delivery
    async confirmDelivery(shipmentId) {
        const recipientName = prompt('Recipient Name:');
        if (!recipientName) return;
        
        const notes = prompt('Delivery Notes (optional):') || '';
        
        try {
            await Api.send('api/plt_delivery.php?action=confirm', 'POST', {
                shipment_id: shipmentId,
                recipient_name: recipientName,
                recipient_relationship: 'Self',
                notes: notes,
                latitude: null, // Would be populated by GPS in real app
                longitude: null
            });
            this.showSuccess('Delivery confirmed successfully');
            this.loadShipments();
        } catch (error) {
            console.error('Error confirming delivery:', error);
            this.showError('Failed to confirm delivery');
        }
    },
    
    // Show shipment details modal
    showShipmentModal(shipment) {
        const modal = document.getElementById('shipmentModal');
        if (!modal) return;
        
        // Populate modal with shipment details
        document.getElementById('modalTrackingNumber').textContent = shipment.tracking_number;
        document.getElementById('modalCustomerName').textContent = shipment.customer_name;
        document.getElementById('modalDeliveryAddress').textContent = shipment.delivery_address;
        document.getElementById('modalStatus').innerHTML = `<span class="badge bg-${this.getStatusColor(shipment.status)}">${shipment.status}</span>`;
        document.getElementById('modalPriority').innerHTML = `<span class="badge bg-${this.getPriorityColor(shipment.priority)}">${shipment.priority}</span>`;
        
        // Load tracking events
        this.renderTrackingEvents(shipment.tracking_events || []);
        
        // Show modal
        new bootstrap.Modal(modal).show();
    },
    
    // Show tracking modal
    showTrackingModal(trackingData) {
        const modal = document.getElementById('trackingModal');
        if (!modal) return;
        
        document.getElementById('trackingNumber').textContent = trackingData.tracking_number;
        document.getElementById('trackingStatus').innerHTML = `<span class="badge bg-${this.getStatusColor(trackingData.status)}">${trackingData.status}</span>`;
        document.getElementById('trackingCustomer').textContent = trackingData.customer_name;
        
        // Render tracking timeline
        this.renderTrackingTimeline(trackingData.events || []);
        
        new bootstrap.Modal(modal).show();
    },
    
    // Render tracking events
    renderTrackingEvents(events) {
        const container = document.getElementById('trackingEventsContainer');
        if (!container) return;
        
        container.innerHTML = events.map(event => `
            <div class="d-flex mb-3">
                <div class="flex-shrink-0">
                    <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                        <i class="fas fa-map-marker-alt text-white"></i>
                    </div>
                </div>
                <div class="flex-grow-1 ms-3">
                    <h6 class="mb-1">${event.event_type}</h6>
                    <p class="mb-1 text-muted">${event.description || ''}</p>
                    <small class="text-muted">
                        ${event.location || 'Unknown location'} • 
                        ${new Date(event.event_time).toLocaleString()}
                    </small>
                </div>
            </div>
        `).join('');
    },
    
    // Render tracking timeline
    renderTrackingTimeline(events) {
        const container = document.getElementById('trackingTimeline');
        if (!container) return;
        
        container.innerHTML = events.map((event, index) => `
            <div class="timeline-item ${index === 0 ? 'active' : ''}">
                <div class="timeline-marker"></div>
                <div class="timeline-content">
                    <h6>${event.event_type}</h6>
                    <p class="mb-1">${event.description || ''}</p>
                    <small class="text-muted">
                        ${event.location || ''} • ${new Date(event.event_time).toLocaleString()}
                    </small>
                </div>
            </div>
        `).join('');
    },
    
    // Bind event listeners
    bindEvents() {
        // Filter form
        const filterForm = document.getElementById('shipmentFilterForm');
        if (filterForm) {
            filterForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const formData = new FormData(filterForm);
                this.loadShipments({
                    status: formData.get('status'),
                    search: formData.get('search'),
                    page: 1
                });
            });
        }
        
        // Create shipment form
        const createForm = document.getElementById('createShipmentForm');
        if (createForm) {
            createForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const formData = new FormData(createForm);
                
                const shipmentData = {
                    customer_name: formData.get('customer_name'),
                    customer_email: formData.get('customer_email'),
                    customer_phone: formData.get('customer_phone'),
                    delivery_address: formData.get('delivery_address'),
                    city: formData.get('city'),
                    postal_code: formData.get('postal_code'),
                    priority: formData.get('priority'),
                    warehouse_id: parseInt(formData.get('warehouse_id')),
                    items: this.getShipmentItems() // Custom function to get items
                };
                
                this.createShipment(shipmentData);
            });
        }
        
        // Add tracking event form
        const eventForm = document.getElementById('addEventForm');
        if (eventForm) {
            eventForm.addEventListener('submit', (e) => {
                e.preventDefault();
                if (!this.currentShipment) return;
                
                const formData = new FormData(eventForm);
                this.addTrackingEvent(this.currentShipment.id, {
                    event_type: formData.get('event_type'),
                    location: formData.get('location'),
                    description: formData.get('description'),
                    event_time: formData.get('event_time') || new Date().toISOString()
                });
                
                eventForm.reset();
            });
        }
    },
    
    // Get shipment items from form (placeholder)
    getShipmentItems() {
        // This would be implemented based on your item selection UI
        return [{
            product_id: 1,
            product_name: 'Sample Product',
            quantity: 1,
            weight_kg: 1.0,
            value: 100.00
        }];
    },
    
    // Utility functions
    getStatusColor(status) {
        const colors = {
            'pending': 'secondary',
            'picked': 'info',
            'packed': 'warning',
            'in_transit': 'primary',
            'out_for_delivery': 'warning',
            'delivered': 'success',
            'failed': 'danger',
            'returned': 'dark'
        };
        return colors[status] || 'secondary';
    },
    
    getPriorityColor(priority) {
        const colors = {
            'standard': 'secondary',
            'express': 'warning',
            'urgent': 'danger'
        };
        return colors[priority] || 'secondary';
    },
    
    truncateText(text, maxLength) {
        if (!text) return '';
        return text.length > maxLength ? text.substring(0, maxLength) + '...' : text;
    },
    
    updatePagination(pagination) {
        const paginationContainer = document.getElementById('paginationContainer');
        if (!paginationContainer || !pagination) return;
        
        const { page, pages, total } = pagination;
        
        paginationContainer.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <small class="text-muted">
                        Page ${page} of ${pages} • ${total} shipments
                    </small>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-secondary" 
                            onclick="PLT.loadShipments({page: ${page - 1}})"
                            ${page <= 1 ? 'disabled' : ''}>
                        Previous
                    </button>
                    <button class="btn btn-sm btn-outline-secondary ms-2" 
                            onclick="PLT.loadShipments({page: ${page + 1}})"
                            ${page >= pages ? 'disabled' : ''}>
                        Next
                    </button>
                </div>
            </div>
        `;
    },
    
    showSuccess(message) {
        // You can implement toast notifications or use your preferred notification system
        alert(message);
    },
    
    showError(message) {
        // You can implement toast notifications or use your preferred notification system
        alert('Error: ' + message);
    }
};

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    if (window.location.pathname.includes('plt.php')) {
        PLT.init();
    }
});
