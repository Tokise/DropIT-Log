<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>DropIT · DTRS/DTLRS</title>
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
        <!-- Documents Tab -->
        <div class="card shadow-sm mb-3">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <h5 class="card-title mb-0"><i class="fa-solid fa-file-lines me-2 text-primary"></i>DTLRS – Document Management</h5>
              <div class="d-flex gap-2">
                <select id="docType" class="form-select form-select-sm" style="width:150px">
                  <option value="">All Types</option>
                  <option value="invoice">Invoice</option>
                  <option value="packing_list">Packing List</option>
                  <option value="delivery_receipt">Delivery Receipt</option>
                  <option value="customs_declaration">Customs Declaration</option>
                  <option value="bill_of_lading">Bill of Lading</option>
                </select>
                <select id="docStatus" class="form-select form-select-sm" style="width:120px">
                  <option value="">All Status</option>
                  <option value="draft">Draft</option>
                  <option value="pending_approval">Pending</option>
                  <option value="approved">Approved</option>
                  <option value="rejected">Rejected</option>
                </select>
                <input id="docSearch" class="form-control form-control-sm" style="width:160px" placeholder="Search documents"/>
                <button id="filterDocsBtn" class="btn btn-outline-primary btn-sm">Filter</button>
                <button id="uploadDocBtn" class="btn btn-primary btn-sm"><i class="fa-solid fa-upload"></i> Upload</button>
                <button id="createDocBtn" class="btn btn-success btn-sm"><i class="fa-solid fa-plus"></i> Create</button>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Document #</th><th>Title</th><th>Type</th><th>Status</th><th>Entity</th><th>Uploaded</th><th>Actions</th>
                  </tr>
                </thead>
                <tbody id="docsRows"><tr><td colspan="7" class="text-muted">Loading documents...</td></tr></tbody>
              </table>
            </div>
            <div class="d-flex align-items-center gap-2">
              <button id="docsPrev" class="btn btn-outline-secondary btn-sm">Prev</button>
              <button id="docsNext" class="btn btn-outline-secondary btn-sm">Next</button>
              <span id="docsMeta" class="text-muted small"></span>
            </div>
          </div>
        </div>

        <!-- Templates Tab -->
        <div class="card shadow-sm mb-3">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <h5 class="card-title mb-0"><i class="fa-solid fa-file-contract me-2 text-secondary"></i>Document Templates</h5>
              <button id="createTemplateBtn" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-plus"></i> Create Template</button>
            </div>
            <div class="table-responsive">
              <table class="table align-middle table-sm">
                <thead class="table-light">
                  <tr>
                    <th>Template Name</th><th>Type</th><th>Created</th><th>Actions</th>
                  </tr>
                </thead>
                <tbody id="templatesRows"><tr><td colspan="4" class="text-muted">Loading templates...</td></tr></tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Document Detail Modal -->
        <div id="docDetailCard" class="card shadow-sm d-none">
          <div class="card-body">
            <h5 class="card-title mb-3"><i class="fa-solid fa-file-alt me-2 text-primary"></i>Document Details <span id="docDetailId"></span></h5>
            <div id="docDetail" class="row g-3"></div>
            <hr/>
            <h6><i class="fa-solid fa-history me-2 text-secondary"></i>Access Log</h6>
            <div class="table-responsive">
              <table class="table table-sm">
                <thead><tr><th>User</th><th>Action</th><th>Date</th><th>IP Address</th></tr></thead>
                <tbody id="accessLogRows"></tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Upload Modal -->
        <div id="uploadModal" class="modal fade" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title">Upload Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <form id="uploadForm" enctype="multipart/form-data">
                  <div class="mb-3">
                    <label class="form-label">Document Title</label>
                    <input type="text" id="uploadTitle" class="form-control" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Document Type</label>
                    <select id="uploadType" class="form-select" required>
                      <option value="">Select type...</option>
                      <option value="invoice">Invoice</option>
                      <option value="packing_list">Packing List</option>
                      <option value="delivery_receipt">Delivery Receipt</option>
                      <option value="customs_declaration">Customs Declaration</option>
                      <option value="bill_of_lading">Bill of Lading</option>
                    </select>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Related Entity (Optional)</label>
                    <div class="row">
                      <div class="col-6">
                        <select id="uploadEntityType" class="form-select">
                          <option value="">None</option>
                          <option value="shipment">Shipment</option>
                          <option value="purchase_order">Purchase Order</option>
                          <option value="asset">Asset</option>
                        </select>
                      </div>
                      <div class="col-6">
                        <input type="number" id="uploadEntityId" class="form-control" placeholder="Entity ID">
                      </div>
                    </div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">File</label>
                    <input type="file" id="uploadFile" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" required>
                    <div class="form-text">Supported: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG (Max 10MB)</div>
                  </div>
                </form>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="uploadSubmit" class="btn btn-primary">Upload Document</button>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/common.js"></script>
  <script>
    let docsPage = 1, limit = 10;
    let docFilters = { document_type: '', status: '', search: '' };
    let uploadModal;

    // Load Documents
    async function loadDocuments() {
      const params = { ...docFilters, page: docsPage, limit };
      const res = await Api.get(`api/dtlrs_documents.php${Api.q(params)}`);
      const docs = res.data.documents || [];
      const total = res.data.pagination?.total || 0;
      
      document.getElementById('docsRows').innerHTML = docs.map(d => {
        const statusColor = {
          'draft': 'secondary', 'pending_approval': 'warning', 
          'approved': 'success', 'rejected': 'danger', 'archived': 'dark'
        }[d.status] || 'secondary';
        
        const entityInfo = d.entity_type ? `${d.entity_type} #${d.entity_id}` : 'None';
        
        return `<tr>
          <td><strong>${d.document_number}</strong></td>
          <td>${d.title}</td>
          <td><span class="badge text-bg-info">${d.document_type.replace('_', ' ')}</span></td>
          <td><span class="badge text-bg-${statusColor}">${d.status.replace('_', ' ')}</span></td>
          <td><small class="text-muted">${entityInfo}</small></td>
          <td><small>${new Date(d.created_at).toLocaleDateString()}<br>by ${d.uploaded_by_name}</small></td>
          <td>
            <button class="btn btn-sm btn-outline-primary" data-view-doc="${d.id}" title="View Details"><i class="fa-solid fa-eye"></i></button>
            ${d.file_path ? `<button class="btn btn-sm btn-outline-success" data-download="${d.id}" title="Download"><i class="fa-solid fa-download"></i></button>` : ''}
            ${d.status === 'pending_approval' ? `<button class="btn btn-sm btn-outline-warning" data-approve="${d.id}" title="Approve"><i class="fa-solid fa-check"></i></button>` : ''}
          </td>
        </tr>`;
      }).join('') || '<tr><td colspan="7" class="text-muted">No documents found</td></tr>';
      
      const pages = Math.max(1, Math.ceil(total / limit));
      document.getElementById('docsPrev').disabled = docsPage <= 1;
      document.getElementById('docsNext').disabled = docsPage >= pages;
      document.getElementById('docsMeta').textContent = `Page ${docsPage}/${pages} • ${total} documents`;
    }

    // Load Templates
    async function loadTemplates() {
      const res = await Api.get('api/dtlrs_documents.php?action=templates');
      const templates = res.data.templates || [];
      
      document.getElementById('templatesRows').innerHTML = templates.map(t => `<tr>
        <td><strong>${t.title}</strong></td>
        <td><span class="badge text-bg-secondary">${t.document_type.replace('_', ' ')}</span></td>
        <td><small>${new Date(t.created_at).toLocaleDateString()}</small></td>
        <td>
          <button class="btn btn-sm btn-outline-primary" data-use-template="${t.id}" title="Use Template"><i class="fa-solid fa-copy"></i></button>
          <button class="btn btn-sm btn-outline-danger" data-delete-template="${t.id}" title="Delete"><i class="fa-solid fa-trash"></i></button>
        </td>
      </tr>`).join('') || '<tr><td colspan="4" class="text-muted">No templates found</td></tr>';
    }

    // Event Listeners
    document.getElementById('filterDocsBtn').addEventListener('click', () => {
      docFilters.document_type = document.getElementById('docType').value;
      docFilters.status = document.getElementById('docStatus').value;
      docFilters.search = document.getElementById('docSearch').value;
      docsPage = 1;
      loadDocuments();
    });

    document.getElementById('uploadDocBtn').addEventListener('click', () => {
      uploadModal.show();
    });

    document.getElementById('createDocBtn').addEventListener('click', () => {
      const title = prompt('Document Title:');
      if (!title) return;
      const type = prompt('Document Type (invoice/packing_list/delivery_receipt/etc):');
      if (!type) return;
      
      createDocument({
        title: title,
        document_type: type,
        status: 'draft'
      });
    });

    document.getElementById('createTemplateBtn').addEventListener('click', () => {
      const title = prompt('Template Name:');
      if (!title) return;
      const type = prompt('Document Type:');
      if (!type) return;
      
      createTemplate({
        title: title,
        document_type: type,
        template_data: {
          fields: [],
          layout: 'standard'
        }
      });
    });

    // Document Actions
    document.getElementById('docsRows').addEventListener('click', (e) => {
      const viewBtn = e.target.closest('button[data-view-doc]');
      const downloadBtn = e.target.closest('button[data-download]');
      const approveBtn = e.target.closest('button[data-approve]');
      
      if (viewBtn) {
        const docId = parseInt(viewBtn.getAttribute('data-view-doc'));
        viewDocumentDetails(docId);
      }
      
      if (downloadBtn) {
        const docId = parseInt(downloadBtn.getAttribute('data-download'));
        downloadDocument(docId);
      }
      
      if (approveBtn) {
        const docId = parseInt(approveBtn.getAttribute('data-approve'));
        approveDocument(docId);
      }
    });

    // Template Actions
    document.getElementById('templatesRows').addEventListener('click', (e) => {
      const useBtn = e.target.closest('button[data-use-template]');
      const deleteBtn = e.target.closest('button[data-delete-template]');
      
      if (useBtn) {
        const templateId = parseInt(useBtn.getAttribute('data-use-template'));
        useTemplate(templateId);
      }
      
      if (deleteBtn) {
        const templateId = parseInt(deleteBtn.getAttribute('data-delete-template'));
        if (confirm('Delete this template?')) {
          deleteTemplate(templateId);
        }
      }
    });

    // Upload Form
    document.getElementById('uploadSubmit').addEventListener('click', () => {
      uploadDocument();
    });

    // Pagination
    document.getElementById('docsPrev').addEventListener('click', () => {
      if (docsPage > 1) { docsPage--; loadDocuments(); }
    });
    document.getElementById('docsNext').addEventListener('click', () => {
      docsPage++; loadDocuments();
    });

    // API Functions
    async function createDocument(data) {
      try {
        const res = await Api.send('api/dtlrs_documents.php', 'POST', data);
        alert(`Document created! Number: ${res.data.document_number}`);
        loadDocuments();
      } catch (e) {
        alert('Error creating document');
      }
    }

    async function uploadDocument() {
      const form = document.getElementById('uploadForm');
      const formData = new FormData();
      
      formData.append('file', document.getElementById('uploadFile').files[0]);
      formData.append('title', document.getElementById('uploadTitle').value);
      formData.append('document_type', document.getElementById('uploadType').value);
      formData.append('entity_type', document.getElementById('uploadEntityType').value);
      formData.append('entity_id', document.getElementById('uploadEntityId').value);
      
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
          alert(`Document uploaded! Number: ${result.data.document_number}`);
          uploadModal.hide();
          form.reset();
          loadDocuments();
        } else {
          alert('Upload failed: ' + result.error);
        }
      } catch (e) {
        alert('Upload error');
      }
    }

    async function viewDocumentDetails(docId) {
      try {
        const res = await Api.get(`api/dtlrs_documents.php?id=${docId}`);
        const doc = res.data;
        
        document.getElementById('docDetailCard').classList.remove('d-none');
        document.getElementById('docDetailId').textContent = `#${doc.id}`;
        
        document.getElementById('docDetail').innerHTML = `
          <div class="col-md-4"><div class="text-muted small">Document Number</div><div><strong>${doc.document_number}</strong></div></div>
          <div class="col-md-4"><div class="text-muted small">Title</div><div>${doc.title}</div></div>
          <div class="col-md-4"><div class="text-muted small">Type</div><div>${doc.document_type}</div></div>
          <div class="col-md-4"><div class="text-muted small">Status</div><div><span class="badge text-bg-success">${doc.status}</span></div></div>
          <div class="col-md-4"><div class="text-muted small">File Size</div><div>${doc.file_size ? (doc.file_size / 1024).toFixed(1) + ' KB' : 'N/A'}</div></div>
          <div class="col-md-4"><div class="text-muted small">Version</div><div>${doc.version || 1}</div></div>
          <div class="col-md-6"><div class="text-muted small">Uploaded By</div><div>${doc.uploaded_by_name}</div></div>
          <div class="col-md-6"><div class="text-muted small">Created</div><div>${new Date(doc.created_at).toLocaleString()}</div></div>
        `;
        
        // Load access log
        const accessLog = doc.access_log || [];
        document.getElementById('accessLogRows').innerHTML = accessLog.map(log => `<tr>
          <td>${log.user_name}</td>
          <td><span class="badge text-bg-info">${log.action}</span></td>
          <td><small>${new Date(log.created_at).toLocaleString()}</small></td>
          <td><small class="text-muted">${log.ip_address || 'N/A'}</small></td>
        </tr>`).join('') || '<tr><td colspan="4" class="text-muted">No access log</td></tr>';
        
      } catch (e) {
        alert('Error loading document details');
      }
    }

    async function downloadDocument(docId) {
      try {
        window.open(`api/dtlrs_documents.php?action=download&id=${docId}`, '_blank');
      } catch (e) {
        alert('Error downloading document');
      }
    }

    async function approveDocument(docId) {
      try {
        await Api.send('api/dtlrs_documents.php?action=approve', 'PUT', {
          document_id: docId
        });
        alert('Document approved');
        loadDocuments();
      } catch (e) {
        alert('Error approving document');
      }
    }

    async function createTemplate(data) {
      try {
        const res = await Api.send('api/dtlrs_documents.php?action=create_template', 'POST', data);
        alert('Template created successfully');
        loadTemplates();
      } catch (e) {
        alert('Error creating template');
      }
    }

    async function useTemplate(templateId) {
      const title = prompt('Document Title:');
      if (!title) return;
      
      try {
        const res = await Api.send('api/dtlrs_documents.php', 'POST', {
          title: title,
          template_id: templateId,
          status: 'draft'
        });
        alert(`Document created from template! Number: ${res.data.document_number}`);
        loadDocuments();
      } catch (e) {
        alert('Error using template');
      }
    }

    async function deleteTemplate(templateId) {
      try {
        await Api.send(`api/dtlrs_documents.php?id=${templateId}`, 'DELETE');
        alert('Template deleted');
        loadTemplates();
      } catch (e) {
        alert('Error deleting template');
      }
    }

    // Initialize
    document.addEventListener('DOMContentLoaded', () => {
      uploadModal = new bootstrap.Modal(document.getElementById('uploadModal'));
      loadDocuments();
      loadTemplates();
    });
  </script>
</body>
</html>
