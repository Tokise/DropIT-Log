// DTLRS (Document Tracking & Logistics Records System) Module JavaScript
const DTLRS = {
    currentDocument: null,
    uploadModal: null,
    
    // Initialize DTLRS module
    init() {
        this.loadDocuments();
        this.loadTemplates();
        this.bindEvents();
        this.initializeModals();
    },
    
    // Initialize Bootstrap modals
    initializeModals() {
        const uploadModalEl = document.getElementById('uploadModal');
        if (uploadModalEl) {
            this.uploadModal = new bootstrap.Modal(uploadModalEl);
        }
    },
    
    // Load documents with filters
    async loadDocuments(filters = {}) {
        try {
            const params = new URLSearchParams({
                page: filters.page || 1,
                limit: filters.limit || 20,
                document_type: filters.document_type || '',
                status: filters.status || '',
                search: filters.search || ''
            });
            
            const response = await Api.get(`api/dtlrs_documents.php?${params}`);
            this.renderDocuments(response.data.documents);
            this.updateDocumentsPagination(response.data.pagination);
        } catch (error) {
            console.error('Error loading documents:', error);
            this.showError('Failed to load documents');
        }
    },
    
    // Load document templates
    async loadTemplates() {
        try {
            const response = await Api.get('api/dtlrs_documents.php?action=templates');
            this.renderTemplates(response.data.templates);
        } catch (error) {
            console.error('Error loading templates:', error);
            this.showError('Failed to load templates');
        }
    },
    
    // Render documents table
    renderDocuments(documents) {
        const tbody = document.getElementById('documentsTableBody');
        if (!tbody) return;
        
        tbody.innerHTML = documents.map(doc => `
            <tr>
                <td><strong>${doc.document_number}</strong></td>
                <td>${doc.title}</td>
                <td><span class="badge bg-info">${this.formatDocumentType(doc.document_type)}</span></td>
                <td><span class="badge bg-${this.getStatusColor(doc.status)}">${this.formatStatus(doc.status)}</span></td>
                <td>
                    <small class="text-muted">
                        ${doc.entity_type ? `${doc.entity_type} #${doc.entity_id}` : 'None'}
                    </small>
                </td>
                <td>
                    <small>
                        ${new Date(doc.created_at).toLocaleDateString()}<br>
                        by ${doc.uploaded_by_name}
                    </small>
                </td>
                <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="DTLRS.viewDocument(${doc.id})" title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                    ${doc.file_path ? `
                        <button class="btn btn-sm btn-outline-success" onclick="DTLRS.downloadDocument(${doc.id})" title="Download">
                            <i class="fas fa-download"></i>
                        </button>
                    ` : ''}
                    ${doc.status === 'pending_approval' ? `
                        <button class="btn btn-sm btn-outline-warning" onclick="DTLRS.approveDocument(${doc.id})" title="Approve">
                            <i class="fas fa-check"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="DTLRS.rejectDocument(${doc.id})" title="Reject">
                            <i class="fas fa-times"></i>
                        </button>
                    ` : ''}
                </td>
            </tr>
        `).join('');
    },
    
    // Render templates table
    renderTemplates(templates) {
        const tbody = document.getElementById('templatesTableBody');
        if (!tbody) return;
        
        tbody.innerHTML = templates.map(template => `
            <tr>
                <td><strong>${template.title}</strong></td>
                <td><span class="badge bg-secondary">${this.formatDocumentType(template.document_type)}</span></td>
                <td><small>${new Date(template.created_at).toLocaleDateString()}</small></td>
                <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="DTLRS.useTemplate(${template.id})" title="Use Template">
                        <i class="fas fa-copy"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-info" onclick="DTLRS.viewDocument(${template.id})" title="View">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="DTLRS.deleteTemplate(${template.id})" title="Delete">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="4" class="text-muted text-center">No templates found</td></tr>';
    },
    
    // Create new document
    async createDocument(data) {
        try {
            const response = await Api.send('api/dtlrs_documents.php', 'POST', data);
            this.showSuccess(`Document created! Number: ${response.data.document_number}`);
            this.loadDocuments();
            return response.data;
        } catch (error) {
            console.error('Error creating document:', error);
            this.showError('Failed to create document');
        }
    },
    
    // Upload document
    async uploadDocument(formData) {
        try {
            const response = await fetch('api/dtlrs_documents.php?action=upload', {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${localStorage.getItem('auth_token')}`
                },
                body: formData
            });
            
            const result = await response.json();
            if (result.ok) {
                this.showSuccess(`Document uploaded! Number: ${result.data.document_number}`);
                this.uploadModal.hide();
                this.loadDocuments();
                return result.data;
            } else {
                throw new Error(result.error || 'Upload failed');
            }
        } catch (error) {
            console.error('Error uploading document:', error);
            this.showError('Failed to upload document: ' + error.message);
        }
    },
    
    // View document details
    async viewDocument(documentId) {
        try {
            const response = await Api.get(`api/dtlrs_documents.php?id=${documentId}`);
            this.currentDocument = response.data;
            this.showDocumentModal(response.data);
        } catch (error) {
            console.error('Error loading document:', error);
            this.showError('Failed to load document details');
        }
    },
    
    // Download document
    downloadDocument(documentId) {
        try {
            window.open(`api/dtlrs_documents.php?action=download&id=${documentId}`, '_blank');
        } catch (error) {
            console.error('Error downloading document:', error);
            this.showError('Failed to download document');
        }
    },
    
    // Approve document
    async approveDocument(documentId) {
        if (!confirm('Are you sure you want to approve this document?')) return;
        
        try {
            await Api.send('api/dtlrs_documents.php?action=approve', 'PUT', {
                document_id: documentId
            });
            this.showSuccess('Document approved successfully');
            this.loadDocuments();
        } catch (error) {
            console.error('Error approving document:', error);
            this.showError('Failed to approve document');
        }
    },
    
    // Reject document
    async rejectDocument(documentId) {
        const reason = prompt('Reason for rejection (optional):');
        if (reason === null) return; // User cancelled
        
        try {
            await Api.send('api/dtlrs_documents.php?action=reject', 'PUT', {
                document_id: documentId,
                reason: reason
            });
            this.showSuccess('Document rejected');
            this.loadDocuments();
        } catch (error) {
            console.error('Error rejecting document:', error);
            this.showError('Failed to reject document');
        }
    },
    
    // Create template
    async createTemplate(data) {
        try {
            const response = await Api.send('api/dtlrs_documents.php?action=create_template', 'POST', data);
            this.showSuccess('Template created successfully');
            this.loadTemplates();
            return response.data;
        } catch (error) {
            console.error('Error creating template:', error);
            this.showError('Failed to create template');
        }
    },
    
    // Use template to create document
    async useTemplate(templateId) {
        const title = prompt('Document Title:');
        if (!title) return;
        
        try {
            const response = await Api.send('api/dtlrs_documents.php', 'POST', {
                title: title,
                template_id: templateId,
                status: 'draft'
            });
            this.showSuccess(`Document created from template! Number: ${response.data.document_number}`);
            this.loadDocuments();
        } catch (error) {
            console.error('Error using template:', error);
            this.showError('Failed to create document from template');
        }
    },
    
    // Delete template
    async deleteTemplate(templateId) {
        if (!confirm('Are you sure you want to delete this template?')) return;
        
        try {
            await Api.send(`api/dtlrs_documents.php?id=${templateId}`, 'DELETE');
            this.showSuccess('Template deleted successfully');
            this.loadTemplates();
        } catch (error) {
            console.error('Error deleting template:', error);
            this.showError('Failed to delete template');
        }
    },
    
    // Show document details modal
    showDocumentModal(document) {
        const modal = document.getElementById('documentModal');
        if (!modal) return;
        
        // Populate modal with document details
        document.getElementById('modalDocumentNumber').textContent = document.document_number;
        document.getElementById('modalDocumentTitle').textContent = document.title;
        document.getElementById('modalDocumentType').textContent = this.formatDocumentType(document.document_type);
        document.getElementById('modalDocumentStatus').innerHTML = `<span class="badge bg-${this.getStatusColor(document.status)}">${this.formatStatus(document.status)}</span>`;
        document.getElementById('modalFileSize').textContent = document.file_size ? `${(document.file_size / 1024).toFixed(1)} KB` : 'N/A';
        document.getElementById('modalVersion').textContent = document.version || '1';
        document.getElementById('modalUploadedBy').textContent = document.uploaded_by_name;
        document.getElementById('modalCreatedAt').textContent = new Date(document.created_at).toLocaleString();
        
        // Load access log
        this.renderAccessLog(document.access_log || []);
        
        // Load versions if available
        this.renderVersions(document.versions || []);
        
        // Show modal
        new bootstrap.Modal(modal).show();
    },
    
    // Render access log
    renderAccessLog(accessLog) {
        const container = document.getElementById('accessLogContainer');
        if (!container) return;
        
        container.innerHTML = accessLog.map(log => `
            <tr>
                <td>${log.user_name}</td>
                <td><span class="badge bg-info">${log.action}</span></td>
                <td><small>${new Date(log.created_at).toLocaleString()}</small></td>
                <td><small class="text-muted">${log.ip_address || 'N/A'}</small></td>
            </tr>
        `).join('') || '<tr><td colspan="4" class="text-muted text-center">No access log available</td></tr>';
    },
    
    // Render document versions
    renderVersions(versions) {
        const container = document.getElementById('versionsContainer');
        if (!container) return;
        
        container.innerHTML = versions.map(version => `
            <tr>
                <td>v${version.version_number}</td>
                <td><small>${new Date(version.created_at).toLocaleString()}</small></td>
                <td>${version.created_by_name}</td>
                <td><small>${version.changes_summary || 'N/A'}</small></td>
                <td>
                    ${version.file_path ? `
                        <button class="btn btn-sm btn-outline-primary" onclick="DTLRS.downloadVersion('${version.file_path}')" title="Download">
                            <i class="fas fa-download"></i>
                        </button>
                    ` : ''}
                </td>
            </tr>
        `).join('') || '<tr><td colspan="5" class="text-muted text-center">No versions available</td></tr>';
    },
    
    // Download specific version
    downloadVersion(filePath) {
        // This would need to be implemented based on your file storage system
        window.open(filePath, '_blank');
    },
    
    // Bind event listeners
    bindEvents() {
        // Document filter form
        const documentFilterForm = document.getElementById('documentFilterForm');
        if (documentFilterForm) {
            documentFilterForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const formData = new FormData(documentFilterForm);
                this.loadDocuments({
                    document_type: formData.get('document_type'),
                    status: formData.get('status'),
                    search: formData.get('search'),
                    page: 1
                });
            });
        }
        
        // Upload form
        const uploadForm = document.getElementById('uploadForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', (e) => {
                e.preventDefault();
                
                const formData = new FormData();
                const fileInput = document.getElementById('uploadFile');
                
                if (!fileInput.files[0]) {
                    this.showError('Please select a file to upload');
                    return;
                }
                
                formData.append('file', fileInput.files[0]);
                formData.append('title', document.getElementById('uploadTitle').value);
                formData.append('document_type', document.getElementById('uploadType').value);
                formData.append('entity_type', document.getElementById('uploadEntityType').value);
                formData.append('entity_id', document.getElementById('uploadEntityId').value);
                
                this.uploadDocument(formData);
            });
        }
        
        // Create document form
        const createDocumentForm = document.getElementById('createDocumentForm');
        if (createDocumentForm) {
            createDocumentForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const formData = new FormData(createDocumentForm);
                
                const documentData = {
                    title: formData.get('title'),
                    document_type: formData.get('document_type'),
                    entity_type: formData.get('entity_type') || null,
                    entity_id: formData.get('entity_id') || null,
                    status: 'draft'
                };
                
                this.createDocument(documentData);
            });
        }
        
        // Create template form
        const createTemplateForm = document.getElementById('createTemplateForm');
        if (createTemplateForm) {
            createTemplateForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const formData = new FormData(createTemplateForm);
                
                const templateData = {
                    title: formData.get('title'),
                    document_type: formData.get('document_type'),
                    template_data: {
                        fields: [],
                        layout: 'standard'
                    }
                };
                
                this.createTemplate(templateData);
            });
        }
        
        // Upload modal show event
        const uploadModalEl = document.getElementById('uploadModal');
        if (uploadModalEl) {
            uploadModalEl.addEventListener('hidden.bs.modal', () => {
                // Reset form when modal is hidden
                const form = document.getElementById('uploadForm');
                if (form) form.reset();
            });
        }
    },
    
    // Utility functions
    getStatusColor(status) {
        const colors = {
            'draft': 'secondary',
            'pending_approval': 'warning',
            'approved': 'success',
            'rejected': 'danger',
            'archived': 'dark'
        };
        return colors[status] || 'secondary';
    },
    
    formatStatus(status) {
        return status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    },
    
    formatDocumentType(type) {
        return type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    },
    
    updateDocumentsPagination(pagination) {
        const container = document.getElementById('documentsPaginationContainer');
        if (!container || !pagination) return;
        
        const { page, pages, total } = pagination;
        
        container.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <small class="text-muted">
                        Page ${page} of ${pages} • ${total} documents
                    </small>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-secondary" 
                            onclick="DTLRS.loadDocuments({page: ${page - 1}})"
                            ${page <= 1 ? 'disabled' : ''}>
                        Previous
                    </button>
                    <button class="btn btn-sm btn-outline-secondary ms-2" 
                            onclick="DTLRS.loadDocuments({page: ${page + 1}})"
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
    if (window.location.pathname.includes('dtrs.php')) {
        DTLRS.init();
    }
});
