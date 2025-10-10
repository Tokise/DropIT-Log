# New PSM Workflow - Supplier Product Catalog System

## 🔄 Workflow Changes

### ❌ OLD WORKFLOW (Incorrect)
1. SWS manually adds products to inventory
2. PSM creates PO and selects from SWS products
3. Supplier fulfills order
4. Items received directly update inventory

**Problem:** Products had to exist in inventory before purchasing!

---

### ✅ NEW WORKFLOW (Correct)
1. **Suppliers maintain their own product catalog**
2. **PSM creates PO by selecting from supplier's products**
3. **Items are received at warehouse**
4. **Warehouse staff adds received items to SWS inventory**
5. **Now products are available in SWS**

---

## 📊 Database Changes

### New Tables Created:

#### 1. `supplier_products`
- Stores products offered by each supplier
- Each supplier has their own catalog
- Fields:
  - `supplier_id` - Which supplier offers this
  - `product_name` - Product name
  - `product_code` - Supplier's product code/SKU
  - `unit_price` - Price from supplier
  - `minimum_order_qty` - Minimum order quantity
  - `lead_time_days` - Expected delivery time
  - `category`, `description`, etc.

#### 2. `received_items`
- Temporary holding area for received PO items
- Items wait here before being added to inventory
- Fields:
  - `po_id` - Which PO this came from
  - `supplier_product_id` - Original supplier product
  - `quantity_received` - How much was received
  - `status` - `pending`, `added_to_inventory`, `rejected`
  - `inventory_product_id` - Links to SWS product after adding

#### 3. Modified `po_items`
- Added `supplier_product_id` column
- Now references supplier products instead of inventory products
- Backward compatible with old `product_id` system

---

## 🔌 New API Endpoints

### 1. `/api/supplier_products.php`
**Purpose:** Manage supplier product catalogs

**GET** - Get supplier products
```
GET /api/supplier_products.php?supplier_id=1
Returns: List of products from supplier #1
```

**POST** - Add product to supplier catalog
```json
{
  "supplier_id": 1,
  "product_name": "Office Chair",
  "product_code": "CHAIR-001",
  "unit_price": 5500.00,
  "minimum_order_qty": 5,
  "lead_time_days": 14
}
```

**PUT** - Update supplier product
**DELETE** - Remove product from catalog

---

### 2. `/api/received_items.php`
**Purpose:** Manage received items before adding to inventory

**GET** - Get received items
```
GET /api/received_items.php?status=pending
Returns: Items waiting to be added to inventory
```

**POST** - Add received item to inventory
```json
{
  "id": 123,
  "description": "Office chair - black leather",
  "reorder_point": 10,
  "notes": "Added to inventory"
}
```

---

## 🔄 Updated Workflow Steps

### Step 1: Supplier Setup (One-time)
1. Admin/PSM adds suppliers
2. **NEW:** Add products to supplier's catalog
   - Go to supplier details
   - Add their products with prices
   - Set minimum order quantities

### Step 2: Create Purchase Order
1. PSM user creates new PO
2. Select supplier
3. **NEW:** Select products from **supplier's catalog** (not SWS inventory!)
4. Set quantities and confirm
5. Submit for approval

### Step 3: PO Approval
1. Supplier receives notification
2. Supplier approves/rejects PO
3. If approved, supplier marks as "Sent"

### Step 4: Receive Items (Warehouse)
1. Items arrive at warehouse
2. PSM/Warehouse marks PO as "Received"
3. **NEW:** Items go to "Received Items" table (not directly to inventory)

### Step 5: Add to Inventory (Warehouse)
1. **NEW:** Warehouse staff reviews received items
2. For each item, they can:
   - **Add to Inventory** - Creates/updates product in SWS
   - **Reject** - Mark as damaged/incorrect
3. Once added, item links to SWS product
4. Now available for use in warehouse operations

---

## 📝 Migration Steps

### 1. Run Database Schema
```sql
-- Run this in phpMyAdmin
source database/supplier_products_schema.sql;
```

### 2. Add Sample Supplier Products
```sql
-- Example: Add products for existing supplier
INSERT INTO supplier_products 
(supplier_id, product_name, product_code, unit_price, minimum_order_qty) 
VALUES 
(1, 'Office Chair', 'CHAIR-001', 5500.00, 5),
(1, 'Desk Lamp', 'LAMP-001', 850.00, 10);
```

### 3. Update Frontend (PSM)
- Modify PO creation form to:
  - Fetch products from `/api/supplier_products.php?supplier_id=X`
  - Send `supplier_product_id` instead of `product_id`
  - Display supplier's product codes and prices

### 4. Add Warehouse Receiving Page
- Create page to view `/api/received_items.php?status=pending`
- Add "Add to Inventory" button for each item
- Form to set reorder point, description, etc.

---

## 🎯 Benefits

1. ✅ **Realistic workflow** - Matches real-world procurement
2. ✅ **Supplier catalogs** - Each supplier has their own products
3. ✅ **Quality control** - Review items before adding to inventory
4. ✅ **Flexibility** - Can reject damaged/incorrect items
5. ✅ **Audit trail** - Track where inventory came from
6. ✅ **Backward compatible** - Old POs still work

---

## 🔍 Example Flow

```
1. ABC Corp (Supplier) has catalog:
   - Office Chair (CHAIR-001) - ₱5,500
   - Desk Lamp (LAMP-001) - ₱850

2. PSM creates PO:
   - Select ABC Corp
   - Add: 10x Office Chair @ ₱5,500
   - Add: 20x Desk Lamp @ ₱850
   - Total: ₱72,000

3. ABC Corp approves and ships

4. Warehouse receives:
   - 10x Office Chair ✓
   - 20x Desk Lamp ✓
   - Items go to "Received Items"

5. Warehouse staff reviews:
   - Office Chair → Add to Inventory
     - Creates "Office Chair Executive" in SWS
     - Quantity: 10
     - SKU: CHAIR-001
   - Desk Lamp → Add to Inventory
     - Creates "LED Desk Lamp" in SWS
     - Quantity: 20
     - SKU: LAMP-001

6. Now SWS has:
   - Office Chair Executive (10 pcs)
   - LED Desk Lamp (20 pcs)
   - Ready for warehouse operations!
```

---

## 📋 TODO for Frontend

- [ ] Create Supplier Products management page
- [ ] Update PO creation to use supplier products
- [ ] Create Received Items page in warehouse
- [ ] Add "Add to Inventory" workflow
- [ ] Update PO details to show supplier product info

---

## 🚀 Next Steps

1. Run the SQL schema file
2. Add products to your suppliers
3. Test creating a PO with supplier products
4. Test the receiving workflow
5. Verify items appear in SWS inventory

---

**This new system properly separates:**
- **Supplier Catalogs** (what suppliers offer)
- **Purchase Orders** (what we're buying)
- **Received Items** (what arrived)
- **SWS Inventory** (what we have)
