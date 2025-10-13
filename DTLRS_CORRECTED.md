# 📄 DTRS/DTLRS (Document Tracking & Logistics Records System)

## Overview

**DTRS/DTLRS** manages all logistics documentation, compliance records, shipping documents, and regulatory paperwork.

## Key Features

- **Document Management:** Upload, version control, approval workflows
- **Customs Documentation:** Declarations, clearances, compliance
- **Delivery Receipts:** Digital signatures, photo proof
- **Compliance Tracking:** Regulatory requirements, audits
- **Workflow Automation:** Multi-step approval processes

## Core Tables

1. **documents** - Document registry
2. **document_versions** - Version history
3. **document_workflows** - Approval workflows
4. **shipment_documents** - Link documents to shipments
5. **compliance_records** - Compliance tracking
6. **customs_declarations** - Customs paperwork
7. **delivery_receipts** - Delivery confirmation
8. **document_access_log** - Audit trail

## Integration Points

### PLT Integration
- Shipment documents (packing list, waybill, delivery receipt)
- Delivery confirmation with signature/photo
- Document status updates

### PSM Integration
- Purchase order documents
- Supplier invoices
- Certificates and compliance docs

### Customs Integration
- Export/import declarations
- HS code classification
- Duty and tax calculations
- Clearance tracking

## API Endpoints

- `POST /api/dtlrs_documents.php` - Create/upload document
- `PUT /api/dtlrs_documents.php?action=approve` - Approve document
- `POST /api/dtlrs_workflow.php` - Create workflow
- `POST /api/dtlrs_customs.php` - Create customs declaration
- `POST /api/dtlrs_delivery.php` - Create delivery receipt

## Document Types

- Invoice
- Packing List
- Bill of Lading
- Delivery Receipt
- Customs Declaration
- Certificate of Origin
- Insurance Certificate
- Purchase Order
- Sales Order
- Waybill
- Manifest

**DTLRS ensures proper documentation and compliance for all logistics operations!** 📄
