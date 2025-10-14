// ALMS (Asset & Lifecycle Management System) Module JavaScript
const ALMS = {
    currentAsset: null,
    
    // Initialize ALMS module
    init() {
        this.loadAssets();
        this.loadMaintenance();
        this.bindEvents();
    },
    
    // Load assets with filters
    async loadAssets(filters = {}) {
        try {
            const params = new URLSearchParams({
                page: filters.page || 1,
                limit: filters.limit || 20,
                asset_type: filters.asset_type || '',
                status: filters.status || '',
                search: filters.search || ''
            });
            
            const response = await Api.get(`api/alms_assets.php?${params}`);
            this.renderAssets(response.data.assets);
            this.updateAssetsPagination(response.data.pagination);
        } catch (error) {
            console.error('Error loading assets:', error);
            this.showError('Failed to load assets');
        }
    },
    
    // Load maintenance schedule
    async loadMaintenance(filters = {}) {
        try {
            const params = new URLSearchParams({
                page: filters.page || 1,
                limit: filters.limit || 20,
                status: filters.status || '',
                asset_id: filters.asset_id || ''
            });
            
            const response = await Api.get(`api/alms_maintenance.php?${params}`);
            this.renderMaintenance(response.data.maintenance);
            this.updateMaintenancePagination(response.data.pagination);
        } catch (error) {
            console.error('Error loading maintenance:', error);
            this.showError('Failed to load maintenance schedule');
        }
    },
    
    // Render assets table
    renderAssets(assets) {
        const tbody = document.getElementById('assetsTableBody');
        if (!tbody) return;
        
        tbody.innerHTML = assets.map(asset => `
            <tr>
                <td><strong>${asset.asset_code}</strong></td>
                <td>${asset.asset_name}</td>
                <td><span class="badge bg-info">${asset.asset_type}</span></td>
                <td><span class="badge bg-${this.getStatusColor(asset.status)}">${asset.status}</span></td>
                <td>${asset.location || asset.warehouse_name || 'Not assigned'}</td>
                <td>
                    <small class="${this.getMaintenanceDueClass(asset.days_to_maintenance)}">
                        ${this.formatMaintenanceDue(asset.days_to_maintenance)}
                    </small>
                </td>
                <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="ALMS.viewAsset(${asset.id})" title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-success" onclick="ALMS.scanAsset()" title="Scan QR">
                        <i class="fas fa-qrcode"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-warning" onclick="ALMS.scheduleMaintenance(${asset.id})" title="Schedule Maintenance">
                        <i class="fas fa-wrench"></i>
                    </button>
                </td>
            </tr>
        `).join('');
    },
    
    // Render maintenance table
    renderMaintenance(maintenance) {
        const tbody = document.getElementById('maintenanceTableBody');
        if (!tbody) return;
        
        tbody.innerHTML = maintenance.map(item => `
            <tr>
                <td>
                    <strong>${item.asset_code}</strong><br>
                    <small class="text-muted">${item.asset_name}</small>
                </td>
                <td><span class="badge bg-info">${item.maintenance_type}</span></td>
                <td>${item.title}</td>
                <td>${new Date(item.scheduled_date).toLocaleDateString()}</td>
                <td><span class="badge bg-${this.getPriorityColor(item.priority)}">${item.priority}</span></td>
                <td><span class="badge bg-${this.getMaintenanceStatusColor(item.status)}">${item.status}</span></td>
                <td>
                    ${item.status === 'scheduled' || item.status === 'overdue' ? `
                        <button class="btn btn-sm btn-outline-success" onclick="ALMS.completeMaintenance(${item.id})" title="Complete">
                            <i class="fas fa-check"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-warning" onclick="ALMS.rescheduleMaintenance(${item.id})" title="Reschedule">
                            <i class="fas fa-calendar"></i>
                        </button>
                    ` : ''}
                    <button class="btn btn-sm btn-outline-info" onclick="ALMS.viewMaintenance(${item.id})" title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                </td>
            </tr>
        `).join('');
    },
    
    // Create new asset
    async createAsset(data) {
        try {
            const response = await Api.send('api/alms_assets.php', 'POST', data);
            this.showSuccess(`Asset created! Code: ${response.data.asset_code}`);
            this.loadAssets();
            return response.data;
        } catch (error) {
            console.error('Error creating asset:', error);
            this.showError('Failed to create asset');
        }
    },
    
    // View asset details
    async viewAsset(assetId) {
        try {
            const response = await Api.get(`api/alms_assets.php?id=${assetId}`);
            this.currentAsset = response.data;
            this.showAssetModal(response.data);
        } catch (error) {
            console.error('Error loading asset:', error);
            this.showError('Failed to load asset details');
        }
    },
    
    // Scan asset QR code
    async scanAsset() {
        const code = prompt('Enter QR Code or Asset Code:');
        if (!code) return;
        
        try {
            const response = await Api.get(`api/alms_assets.php?action=scan&code=${encodeURIComponent(code)}`);
            this.viewAsset(response.data.id);
        } catch (error) {
            console.error('Error scanning asset:', error);
            this.showError('Asset not found');
        }
    },
    
    // Schedule maintenance
    async scheduleMaintenance(assetId, data = null) {
        if (!data) {
            // Show maintenance scheduling modal
            this.showMaintenanceModal(assetId);
            return;
        }
        
        try {
            const response = await Api.send('api/alms_maintenance.php', 'POST', {
                asset_id: assetId,
                ...data
            });
            this.showSuccess('Maintenance scheduled successfully');
            this.loadMaintenance();
            return response.data;
        } catch (error) {
            console.error('Error scheduling maintenance:', error);
            this.showError('Failed to schedule maintenance');
        }
    },
    
    // Complete maintenance
    async completeMaintenance(maintenanceId) {
        const cost = prompt('Actual Cost (optional):');
        const notes = prompt('Completion Notes:');
        
        if (notes === null) return; // User cancelled
        
        try {
            await Api.send('api/alms_maintenance.php?action=complete', 'PUT', {
                maintenance_id: maintenanceId,
                actual_cost: cost ? parseFloat(cost) : 0,
                notes: notes || '',
                completed_date: new Date().toISOString().split('T')[0]
            });
            this.showSuccess('Maintenance completed successfully');
            this.loadMaintenance();
        } catch (error) {
            console.error('Error completing maintenance:', error);
            this.showError('Failed to complete maintenance');
        }
    },
    
    // Reschedule maintenance
    async rescheduleMaintenance(maintenanceId) {
        const newDate = prompt('New Date (YYYY-MM-DD):');
        if (!newDate) return;
        
        try {
            await Api.send('api/alms_maintenance.php?action=reschedule', 'PUT', {
                maintenance_id: maintenanceId,
                new_date: newDate
            });
            this.showSuccess('Maintenance rescheduled successfully');
            this.loadMaintenance();
        } catch (error) {
            console.error('Error rescheduling maintenance:', error);
            this.showError('Failed to reschedule maintenance');
        }
    },
    
    // Update asset status
    async updateAssetStatus(assetId, status, notes = '') {
        try {
            await Api.send('api/alms_assets.php?action=update_status', 'PUT', {
                asset_id: assetId,
                status: status,
                notes: notes
            });
            this.showSuccess('Asset status updated');
            this.loadAssets();
        } catch (error) {
            console.error('Error updating asset status:', error);
            this.showError('Failed to update asset status');
        }
    },
    
    // Transfer asset
    async transferAsset(assetId, transferData) {
        try {
            await Api.send('api/alms_assets.php?action=transfer', 'PUT', {
                asset_id: assetId,
                ...transferData,
                auto_approve: true // Auto-approve for simplicity
            });
            this.showSuccess('Asset transferred successfully');
            this.loadAssets();
        } catch (error) {
            console.error('Error transferring asset:', error);
            this.showError('Failed to transfer asset');
        }
    },
    
    // Show asset details modal
    showAssetModal(asset) {
        const modal = document.getElementById('assetModal');
        if (!modal) return;
        
        // Populate modal with asset details
        document.getElementById('modalAssetCode').textContent = asset.asset_code;
        document.getElementById('modalAssetName').textContent = asset.asset_name;
        document.getElementById('modalAssetType').textContent = asset.asset_type;
        document.getElementById('modalAssetStatus').innerHTML = `<span class="badge bg-${this.getStatusColor(asset.status)}">${asset.status}</span>`;
        document.getElementById('modalAssetLocation').textContent = asset.location || 'Not assigned';
        document.getElementById('modalPurchaseCost').textContent = asset.purchase_cost ? `₱${asset.purchase_cost.toLocaleString()}` : 'N/A';
        document.getElementById('modalCurrentValue').textContent = asset.current_value ? `₱${asset.current_value.toLocaleString()}` : 'N/A';
        
        // Load maintenance history
        this.renderMaintenanceHistory(asset.maintenance_history || []);
        
        // Load depreciation schedule
        this.renderDepreciationSchedule(asset.depreciation_schedule || []);
        
        // Show modal
        new bootstrap.Modal(modal).show();
    },
    
    // Show maintenance scheduling modal
    showMaintenanceModal(assetId) {
        const modal = document.getElementById('maintenanceModal');
        if (!modal) return;
        
        // Set asset ID in form
        document.getElementById('maintenanceAssetId').value = assetId;
        
        // Show modal
        new bootstrap.Modal(modal).show();
    },
    
    // Render maintenance history
    renderMaintenanceHistory(history) {
        const container = document.getElementById('maintenanceHistoryContainer');
        if (!container) return;
        
        container.innerHTML = history.map(item => `
            <tr>
                <td>${new Date(item.scheduled_date).toLocaleDateString()}</td>
                <td><span class="badge bg-info">${item.maintenance_type}</span></td>
                <td><span class="badge bg-${this.getMaintenanceStatusColor(item.status)}">${item.status}</span></td>
                <td>₱${item.cost ? item.cost.toLocaleString() : '0'}</td>
                <td><small>${item.notes || 'N/A'}</small></td>
            </tr>
        `).join('') || '<tr><td colspan="5" class="text-muted text-center">No maintenance history</td></tr>';
    },
    
    // Render depreciation schedule
    renderDepreciationSchedule(schedule) {
        const container = document.getElementById('depreciationScheduleContainer');
        if (!container) return;
        
        container.innerHTML = schedule.map(item => `
            <tr>
                <td>${item.year}-${String(item.month).padStart(2, '0')}</td>
                <td>₱${item.opening_value.toLocaleString()}</td>
                <td>₱${item.depreciation_amount.toLocaleString()}</td>
                <td>₱${item.closing_value.toLocaleString()}</td>
            </tr>
        `).join('') || '<tr><td colspan="4" class="text-muted text-center">No depreciation data</td></tr>';
    },
    
    // Bind event listeners
    bindEvents() {
        // Asset filter form
        const assetFilterForm = document.getElementById('assetFilterForm');
        if (assetFilterForm) {
            assetFilterForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const formData = new FormData(assetFilterForm);
                this.loadAssets({
                    asset_type: formData.get('asset_type'),
                    status: formData.get('status'),
                    search: formData.get('search'),
                    page: 1
                });
            });
        }
        
        // Maintenance filter form
        const maintenanceFilterForm = document.getElementById('maintenanceFilterForm');
        if (maintenanceFilterForm) {
            maintenanceFilterForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const formData = new FormData(maintenanceFilterForm);
                this.loadMaintenance({
                    status: formData.get('status'),
                    page: 1
                });
            });
        }
        
        // Create asset form
        const createAssetForm = document.getElementById('createAssetForm');
        if (createAssetForm) {
            createAssetForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const formData = new FormData(createAssetForm);
                
                const assetData = {
                    asset_name: formData.get('asset_name'),
                    asset_type: formData.get('asset_type'),
                    category: formData.get('category'),
                    manufacturer: formData.get('manufacturer'),
                    model: formData.get('model'),
                    serial_number: formData.get('serial_number'),
                    purchase_date: formData.get('purchase_date'),
                    purchase_cost: parseFloat(formData.get('purchase_cost')) || 0,
                    location: formData.get('location'),
                    warehouse_id: parseInt(formData.get('warehouse_id')) || null
                };
                
                this.createAsset(assetData);
            });
        }
        
        // Schedule maintenance form
        const scheduleMaintenanceForm = document.getElementById('scheduleMaintenanceForm');
        if (scheduleMaintenanceForm) {
            scheduleMaintenanceForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const formData = new FormData(scheduleMaintenanceForm);
                
                const maintenanceData = {
                    maintenance_type: formData.get('maintenance_type'),
                    title: formData.get('title'),
                    description: formData.get('description'),
                    scheduled_date: formData.get('scheduled_date'),
                    priority: formData.get('priority'),
                    estimated_cost: parseFloat(formData.get('estimated_cost')) || 0
                };
                
                const assetId = parseInt(formData.get('asset_id'));
                this.scheduleMaintenance(assetId, maintenanceData);
                
                // Hide modal and reset form
                bootstrap.Modal.getInstance(document.getElementById('maintenanceModal')).hide();
                scheduleMaintenanceForm.reset();
            });
        }
    },
    
    // Utility functions
    getStatusColor(status) {
        const colors = {
            'active': 'success',
            'maintenance': 'warning',
            'retired': 'secondary',
            'disposed': 'dark',
            'lost': 'danger',
            'damaged': 'danger'
        };
        return colors[status] || 'secondary';
    },
    
    getMaintenanceStatusColor(status) {
        const colors = {
            'scheduled': 'primary',
            'overdue': 'danger',
            'in_progress': 'warning',
            'completed': 'success',
            'cancelled': 'secondary'
        };
        return colors[status] || 'secondary';
    },
    
    getPriorityColor(priority) {
        const colors = {
            'low': 'secondary',
            'medium': 'info',
            'high': 'warning',
            'critical': 'danger'
        };
        return colors[priority] || 'secondary';
    },
    
    getMaintenanceDueClass(days) {
        if (days === null || days === undefined) return 'text-muted';
        if (days < 0) return 'text-danger';
        if (days <= 7) return 'text-warning';
        return 'text-muted';
    },
    
    formatMaintenanceDue(days) {
        if (days === null || days === undefined) return 'Not scheduled';
        if (days < 0) return 'Overdue';
        if (days === 0) return 'Due today';
        return `${days} days`;
    },
    
    updateAssetsPagination(pagination) {
        const container = document.getElementById('assetsPaginationContainer');
        if (!container || !pagination) return;
        
        const { page, pages, total } = pagination;
        
        container.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <small class="text-muted">
                        Page ${page} of ${pages} • ${total} assets
                    </small>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-secondary" 
                            onclick="ALMS.loadAssets({page: ${page - 1}})"
                            ${page <= 1 ? 'disabled' : ''}>
                        Previous
                    </button>
                    <button class="btn btn-sm btn-outline-secondary ms-2" 
                            onclick="ALMS.loadAssets({page: ${page + 1}})"
                            ${page >= pages ? 'disabled' : ''}>
                        Next
                    </button>
                </div>
            </div>
        `;
    },
    
    updateMaintenancePagination(pagination) {
        const container = document.getElementById('maintenancePaginationContainer');
        if (!container || !pagination) return;
        
        const { page, pages, total } = pagination;
        
        container.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <small class="text-muted">
                        Page ${page} of ${pages} • ${total} items
                    </small>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-secondary" 
                            onclick="ALMS.loadMaintenance({page: ${page - 1}})"
                            ${page <= 1 ? 'disabled' : ''}>
                        Previous
                    </button>
                    <button class="btn btn-sm btn-outline-secondary ms-2" 
                            onclick="ALMS.loadMaintenance({page: ${page + 1}})"
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
    if (window.location.pathname.includes('alms.php')) {
        ALMS.init();
    }
});
