<?php
require_once 'common.php';

header('Content-Type: application/json');

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $auth = require_auth();
    
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                getDocumentDetails($_GET['id']);
            } elseif ($action === 'download') {
                downloadDocument($_GET['id'] ?? 0);
            } elseif ($action === 'templates') {
                getDocumentTemplates();
            } else {
                getDocuments();
            }
            break;
            
        case 'POST':
            if ($action === 'upload') {
                uploadDocument();
            } elseif ($action === 'create_template') {
                createDocumentTemplate();
            } else {
                createDocument();
            }
            break;
            
        case 'PUT':
            if ($action === 'approve') {
                approveDocument();
            } elseif ($action === 'reject') {
                rejectDocument();
            } else {
                updateDocument();
            }
            break;
            
        case 'DELETE':
            deleteDocument($_GET['id'] ?? 0);
            break;
            
        default:
            json_err('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    json_err('Server error: ' . $e->getMessage(), 500);
}

function getDocuments() {
    global $conn;
    
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = ($page - 1) * $limit;
    
    $document_type = $_GET['document_type'] ?? '';
    $status = $_GET['status'] ?? '';
    $entity_type = $_GET['entity_type'] ?? '';
    $entity_id = $_GET['entity_id'] ?? '';
    $search = $_GET['search'] ?? '';
    
    $where = "WHERE d.is_template = 0";
    $params = [];
    
    if ($document_type) {
        $where .= " AND d.document_type = :document_type";
        $params[':document_type'] = $document_type;
    }
    
    if ($status) {
        $where .= " AND d.status = :status";
        $params[':status'] = $status;
    }
    
    if ($entity_type) {
        $where .= " AND d.entity_type = :entity_type";
        $params[':entity_type'] = $entity_type;
    }
    
    if ($entity_id) {
        $where .= " AND d.entity_id = :entity_id";
        $params[':entity_id'] = $entity_id;
    }
    
    if ($search) {
        $where .= " AND (d.document_number LIKE :search OR d.title LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $sql = "
        SELECT 
            d.*,
            u1.full_name as uploaded_by_name,
            u2.full_name as approved_by_name,
            dw.workflow_name
        FROM documents d
        LEFT JOIN users u1 ON d.uploaded_by = u1.id
        LEFT JOIN users u2 ON d.approved_by = u2.id
        LEFT JOIN document_workflows dw ON d.workflow_id = dw.id
        $where
        ORDER BY d.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $countSql = "SELECT COUNT(*) FROM documents d $where";
    $countStmt = $conn->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = $countStmt->fetchColumn();
    
    json_ok([
        'documents' => $documents,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

function getDocumentDetails($id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            d.*,
            u1.full_name as uploaded_by_name,
            u2.full_name as approved_by_name,
            dw.workflow_name,
            dw.steps as workflow_steps
        FROM documents d
        LEFT JOIN users u1 ON d.uploaded_by = u1.id
        LEFT JOIN users u2 ON d.approved_by = u2.id
        LEFT JOIN document_workflows dw ON d.workflow_id = dw.id
        WHERE d.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$document) {
        json_err('Document not found', 404);
    }
    
    // Get document versions
    $stmt = $conn->prepare("
        SELECT dv.*, u.full_name as created_by_name
        FROM document_versions dv
        LEFT JOIN users u ON dv.created_by = u.id
        WHERE dv.document_id = :id
        ORDER BY dv.version_number DESC
    ");
    $stmt->execute([':id' => $id]);
    $document['versions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get access log
    $stmt = $conn->prepare("
        SELECT dal.*, u.full_name as user_name
        FROM document_access_log dal
        LEFT JOIN users u ON dal.user_id = u.id
        WHERE dal.document_id = :id
        ORDER BY dal.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([':id' => $id]);
    $document['access_log'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    json_ok($document);
}

function getDocumentTemplates() {
    global $conn;
    
    $document_type = $_GET['document_type'] ?? '';
    
    $where = "WHERE d.is_template = 1";
    $params = [];
    
    if ($document_type) {
        $where .= " AND d.document_type = :document_type";
        $params[':document_type'] = $document_type;
    }
    
    $sql = "
        SELECT 
            d.*,
            u.full_name as uploaded_by_name
        FROM documents d
        LEFT JOIN users u ON d.uploaded_by = u.id
        $where
        ORDER BY d.title ASC
    ";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    json_ok(['templates' => $templates]);
}

function createDocument() {
    global $conn, $auth;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        json_err('Invalid JSON data', 400);
    }
    
    // Validate required fields
    $required = ['title', 'document_type'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            json_err("Field '$field' is required", 400);
        }
    }
    
    // Generate document number if not provided
    if (!isset($data['document_number']) || empty($data['document_number'])) {
        $prefix = strtoupper(substr($data['document_type'], 0, 3));
        $year = date('Y');
        $sequence = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $data['document_number'] = "$prefix-$year-$sequence";
    }
    
    $stmt = $conn->prepare("
        INSERT INTO documents (
            document_number, title, document_type, entity_type, entity_id,
            status, file_path, file_size, mime_type, checksum, version,
            is_template, template_data, workflow_id, uploaded_by
        ) VALUES (
            :document_number, :title, :document_type, :entity_type, :entity_id,
            :status, :file_path, :file_size, :mime_type, :checksum, :version,
            :is_template, :template_data, :workflow_id, :uploaded_by
        )
    ");
    
    $templateData = isset($data['template_data']) ? json_encode($data['template_data']) : null;
    
    $stmt->execute([
        ':document_number' => $data['document_number'],
        ':title' => $data['title'],
        ':document_type' => $data['document_type'],
        ':entity_type' => $data['entity_type'] ?? null,
        ':entity_id' => $data['entity_id'] ?? null,
        ':status' => $data['status'] ?? 'draft',
        ':file_path' => $data['file_path'] ?? null,
        ':file_size' => $data['file_size'] ?? null,
        ':mime_type' => $data['mime_type'] ?? null,
        ':checksum' => $data['checksum'] ?? null,
        ':version' => 1,
        ':is_template' => $data['is_template'] ?? 0,
        ':template_data' => $templateData,
        ':workflow_id' => $data['workflow_id'] ?? null,
        ':uploaded_by' => $auth['user_id']
    ]);
    
    $documentId = $conn->lastInsertId();
    
    // Create initial version record
    if (isset($data['file_path'])) {
        $stmt = $conn->prepare("
            INSERT INTO document_versions (
                document_id, version_number, file_path, file_size, 
                checksum, changes_summary, created_by
            ) VALUES (
                :document_id, 1, :file_path, :file_size,
                :checksum, 'Initial version', :created_by
            )
        ");
        
        $stmt->execute([
            ':document_id' => $documentId,
            ':file_path' => $data['file_path'],
            ':file_size' => $data['file_size'] ?? null,
            ':checksum' => $data['checksum'] ?? null,
            ':created_by' => $auth['user_id']
        ]);
    }
    
    json_ok([
        'document_id' => $documentId,
        'document_number' => $data['document_number']
    ]);
}

function uploadDocument() {
    global $conn, $auth;
    
    if (!isset($_FILES['file'])) {
        json_err('No file uploaded', 400);
    }
    
    $file = $_FILES['file'];
    $title = $_POST['title'] ?? '';
    $document_type = $_POST['document_type'] ?? '';
    $entity_type = $_POST['entity_type'] ?? null;
    $entity_id = $_POST['entity_id'] ?? null;
    
    if (empty($title) || empty($document_type)) {
        json_err('Title and document type are required', 400);
    }
    
    // Validate file
    $allowedTypes = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($fileExtension, $allowedTypes)) {
        json_err('File type not allowed', 400);
    }
    
    // Create upload directory
    $uploadDir = '../uploads/documents/' . date('Y/m/');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate unique filename
    $filename = uniqid() . '.' . $fileExtension;
    $filePath = $uploadDir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        json_err('Failed to upload file', 500);
    }
    
    // Calculate checksum
    $checksum = hash_file('sha256', $filePath);
    
    // Generate document number
    $prefix = strtoupper(substr($document_type, 0, 3));
    $year = date('Y');
    $sequence = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    $documentNumber = "$prefix-$year-$sequence";
    
    $conn->beginTransaction();
    
    try {
        // Insert document record
        $stmt = $conn->prepare("
            INSERT INTO documents (
                document_number, title, document_type, entity_type, entity_id,
                status, file_path, file_size, mime_type, checksum, uploaded_by
            ) VALUES (
                :document_number, :title, :document_type, :entity_type, :entity_id,
                'draft', :file_path, :file_size, :mime_type, :checksum, :uploaded_by
            )
        ");
        
        $stmt->execute([
            ':document_number' => $documentNumber,
            ':title' => $title,
            ':document_type' => $document_type,
            ':entity_type' => $entity_type,
            ':entity_id' => $entity_id,
            ':file_path' => $filePath,
            ':file_size' => $file['size'],
            ':mime_type' => $file['type'],
            ':checksum' => $checksum,
            ':uploaded_by' => $auth['user_id']
        ]);
        
        $documentId = $conn->lastInsertId();
        
        // Create version record
        $stmt = $conn->prepare("
            INSERT INTO document_versions (
                document_id, version_number, file_path, file_size, 
                checksum, changes_summary, created_by
            ) VALUES (
                :document_id, 1, :file_path, :file_size,
                :checksum, 'Initial upload', :created_by
            )
        ");
        
        $stmt->execute([
            ':document_id' => $documentId,
            ':file_path' => $filePath,
            ':file_size' => $file['size'],
            ':checksum' => $checksum,
            ':created_by' => $auth['user_id']
        ]);
        
        $conn->commit();
        
        json_ok([
            'document_id' => $documentId,
            'document_number' => $documentNumber,
            'file_path' => $filePath
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        unlink($filePath); // Clean up uploaded file
        throw $e;
    }
}

function approveDocument() {
    global $conn, $auth;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['document_id'])) {
        json_err('Document ID required', 400);
    }
    
    $stmt = $conn->prepare("
        UPDATE documents 
        SET status = 'approved',
            approved_by = :approved_by,
            approved_at = NOW()
        WHERE id = :id AND status = 'pending_approval'
    ");
    
    $stmt->execute([
        ':approved_by' => $auth['user_id'],
        ':id' => $data['document_id']
    ]);
    
    if ($stmt->rowCount() === 0) {
        json_err('Document not found or not pending approval', 404);
    }
    
    // Log access
    logDocumentAccess($data['document_id'], $auth['user_id'], 'approve');
    
    json_ok(['message' => 'Document approved successfully']);
}

function rejectDocument() {
    global $conn, $auth;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['document_id'])) {
        json_err('Document ID required', 400);
    }
    
    $stmt = $conn->prepare("
        UPDATE documents 
        SET status = 'rejected'
        WHERE id = :id AND status = 'pending_approval'
    ");
    
    $stmt->execute([':id' => $data['document_id']]);
    
    if ($stmt->rowCount() === 0) {
        json_err('Document not found or not pending approval', 404);
    }
    
    // Log access
    logDocumentAccess($data['document_id'], $auth['user_id'], 'reject');
    
    json_ok(['message' => 'Document rejected']);
}

function downloadDocument($id) {
    global $conn, $auth;
    
    if (!$id) {
        json_err('Document ID required', 400);
    }
    
    $stmt = $conn->prepare("
        SELECT file_path, title, mime_type 
        FROM documents 
        WHERE id = :id
    ");
    $stmt->execute([':id' => $id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$document || !$document['file_path']) {
        json_err('Document not found', 404);
    }
    
    if (!file_exists($document['file_path'])) {
        json_err('File not found on server', 404);
    }
    
    // Log access
    logDocumentAccess($id, $auth['user_id'], 'download');
    
    // Set headers for download
    header('Content-Type: ' . $document['mime_type']);
    header('Content-Disposition: attachment; filename="' . $document['title'] . '"');
    header('Content-Length: ' . filesize($document['file_path']));
    
    readfile($document['file_path']);
    exit;
}

function updateDocument() {
    global $conn, $auth;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['id'])) {
        json_err('Document ID required', 400);
    }
    
    $updates = [];
    $params = [':id' => $data['id']];
    
    $allowedFields = ['title', 'entity_type', 'entity_id', 'status'];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }
    
    if (empty($updates)) {
        json_err('No valid fields to update', 400);
    }
    
    $sql = "UPDATE documents SET " . implode(', ', $updates) . " WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    // Log access
    logDocumentAccess($data['id'], $auth['user_id'], 'edit');
    
    json_ok(['message' => 'Document updated successfully']);
}

function deleteDocument($id) {
    global $conn, $auth;
    
    if (!$id) {
        json_err('Document ID required', 400);
    }
    
    // Check if document can be deleted (only draft documents)
    $stmt = $conn->prepare("SELECT status, file_path FROM documents WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$document) {
        json_err('Document not found', 404);
    }
    
    if ($document['status'] !== 'draft') {
        json_err('Only draft documents can be deleted', 400);
    }
    
    $conn->beginTransaction();
    
    try {
        // Delete document record (cascade will handle versions and access log)
        $stmt = $conn->prepare("DELETE FROM documents WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        // Delete physical file
        if ($document['file_path'] && file_exists($document['file_path'])) {
            unlink($document['file_path']);
        }
        
        $conn->commit();
        json_ok(['message' => 'Document deleted successfully']);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

function createDocumentTemplate() {
    global $conn, $auth;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        json_err('Invalid JSON data', 400);
    }
    
    // Validate required fields
    $required = ['title', 'document_type', 'template_data'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            json_err("Field '$field' is required", 400);
        }
    }
    
    $documentNumber = 'TPL-' . strtoupper(substr($data['document_type'], 0, 3)) . '-' . time();
    
    $stmt = $conn->prepare("
        INSERT INTO documents (
            document_number, title, document_type, status, is_template,
            template_data, uploaded_by
        ) VALUES (
            :document_number, :title, :document_type, 'approved', 1,
            :template_data, :uploaded_by
        )
    ");
    
    $stmt->execute([
        ':document_number' => $documentNumber,
        ':title' => $data['title'],
        ':document_type' => $data['document_type'],
        ':template_data' => json_encode($data['template_data']),
        ':uploaded_by' => $auth['user_id']
    ]);
    
    $templateId = $conn->lastInsertId();
    
    json_ok([
        'template_id' => $templateId,
        'document_number' => $documentNumber
    ]);
}

function logDocumentAccess($documentId, $userId, $action) {
    global $conn;
    
    $stmt = $conn->prepare("
        INSERT INTO document_access_log (
            document_id, user_id, action, ip_address, user_agent
        ) VALUES (
            :document_id, :user_id, :action, :ip_address, :user_agent
        )
    ");
    
    $stmt->execute([
        ':document_id' => $documentId,
        ':user_id' => $userId,
        ':action' => $action,
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
}
?>
